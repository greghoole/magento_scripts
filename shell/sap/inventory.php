<?php

require_once '../abstract.php';

class Sap_Inventory_Update extends Mage_Shell_Abstract
{
	const MAGENTO_DISABLED = 'Disabled - will stay unavailable on website';
	const NO_SAP_SKUS = 'Enabled but no skus in SAP - will become OUT-OF-STOCK on website';
	const SAP_OUT_OF_STOCK = 'Web in-stock but SAP out-of-stock - will become OUT-OF-STOCK on website';
	const BOTH_IN_STOCK = 'Both website and SAP in-stock - BOH will be ADJUSTED on website';
	const BOTH_EQUAL = 'Both website and SAP in-stock and equal - no changes to BOH necessary';
	const BOTH_OUT_OF_STOCK = 'Both website and SAP out-of-stock - will stay unavailable on website';
	const WEB_OUT_OF_STOCK = 'Web out-of-stock but SAP in-stock - will become AVAILABLE on website';

	private $logFile = '../../var/log/sap_inventory.log';
	private $logHandle;
	private $logResults = [];

	private $resultsFile;

	private $materialsClient;
	private $availabilityClient;

	private $wsdlFiles = [
		'materials' => 'wsdl/QueryMaterialsIn.wsdl',
		'availability' => 'wsdl/ProductAvailabilityDeterminationIn.wsdl'
	];

	private $credentials = [
		'login' => 'USERNAME',
		'password' => 'PASSWORD',
		'trace' => 1
	];

	private $server;

	private $serverSalesOrganizationId;

	private $salesOrganizationIds = [
		'US107' => 'US-SU107',
		'US104' => 'US-SU104'
	];

	public function __construct()
	{
		parent::__construct();

		$this->materialsClient = new SoapClient($this->wsdlFiles['materials'], $this->credentials);
		$this->availabilityClient = new SoapClient($this->wsdlFiles['availability'], $this->credentials);
		
		$this->resultsFile = Mage::getBaseDir() . '/var/log/sap_inventory_results.csv';

		$this->logHandle = fopen($this->logFile, 'w');
	}

	public function run()
	{
		$runNow = false;

		Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

		$enabled = Mage::getStoreConfig('testuser_sap_options/testuser_sap_settings_group/enable_inventory');
		if ($enabled) {
			$siteId = Mage::getStoreConfig('testuser_sap_options/testuser_sap_settings_group/inventory_site_id');
			if (!empty($siteId)) {
				$this->server = $siteId;
				$this->serverSalesOrganizationId = $salesOrganizationIds[$this->server];

				$scheduleParts = explode(',', Mage::getStoreConfig('testuser_sap_options/testuser_sap_settings_group/inventory_schedule'));
				$schedule = $scheduleParts[0] . ':' . $scheduleParts[1];

				if (file_exists(Mage::getBaseDir() . '/shell/sap/run.flag')) { 
					unlink(Mage::getBaseDir() . '/shell/sap/run.flag');
					$runNow = true;
				} elseif ($schedule == date('H:i')) {
					$runNow = true;
				}

			} else {
				echo $this->configureModule();
			}

			if ($runNow) {
				echo 'Start Time: ' . date('m/d/Y h:i:s a') . "\n";
				$resource = Mage::getSingleton('core/resource');
				$readConnection = $resource->getConnection('core_read');

				$query = "SELECT sku FROM catalog_product_entity WHERE type_id = 'configurable'";
				$results = $readConnection->fetchAll($query);

				$query = "SELECT sku FROM catalog_product_entity WHERE type_id = 'simple'";
				$results = $readConnection->fetchAll($query);

				foreach ($results as $row) {
					$this->getSapMaterialsData($row['sku']);
				}

				$this->sendResultsFile();
				echo 'End Time: ' . date('m/d/Y h:i:s a') . "\n";
			}
		}
	}

	public function getSapMaterialsData($magentoSku)
	{
		// load magento product details
		$_product = Mage::getModel('catalog/product')->loadByAttribute('sku', $magentoSku);
		$_productStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($_product);

		// get parent(s)
		$_parentProductIds = Mage::getResourceSingleton('catalog/product_type_configurable')
                                ->getParentIdsByChild($_product->getId());

		// we need to have at least one (N/A)
        if (empty($_parentProductIds)) {
        	$_parentProductIds[0] = 0;
        }

        foreach ($_parentProductIds as $_parentId) {
			
			$matchFound = false;
			$sapQty = 0;
			$change = self::BOTH_IN_STOCK;
			$discontinued = 'False';

        	if ($_parentId != 0) {
            	$_parentProduct = Mage::getModel('catalog/product')->load($_parentId);
                $magentoParentSku = $_parentProduct->getSku();
                unset($_parentProduct);
            } else {
            	$magentoParentSku = 'N/A';
            }
        
			$magentoName = $_product->getName();
			$magentoStatus = $_product->getStatus();
			$magentoVisibility = $_product->getVisibility();
			$magentoColor = $_product->getAttributeText('color');
			$magentoSize = $_product->getAttributeText('size');
			$magentoQty = (int) $_productStock->getQty();

			if ($magentoStatus == Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {

				$searchCriteria = array(
					'MaterialSelectionByElements' => array(
						'SelectionByInternalID' => array(
							'InclusionExclusionCode' => 'I',
							'IntervalBoundaryTypeCode' => '1',
							'LowerBoundaryInternalID' => $magentoSku . '*',
							'UpperBoundaryInternalID' => ''
						),
					),
					'ProcessingConditions' => array(
						'QueryHitsMaximumNumberValue' => 1000,
						'QueryHitsUnlimitedIndicator' => false
					)
				);

				$response = $this->materialsClient->__soapCall('FindByElements', array($searchCriteria));

				if ($response->ProcessingConditions->ReturnedQueryHitsNumberValue == 1) {

					$sapSku = $response->Material->SKU;
					$sapInternalID = $response->Material->InternalID->_;

					if ($response->Material->LifeCycle == 'DSC') {
						$discontinued = 'True';
					}

					if ($magentoSku == $sapSku) {

						$horizon = 'P14D';
						foreach ($response->Material->Sales as $sales) {
							if ($sales->SalesOrganisationID == $this->serverSalesOrganizationId) {
								if ($sales->LifeCycleStatusCode == 3) { // blocked
									$horizon = '999';
								}
							}
						}

						$this->logMessage('Match found in SAP for Magento Sku: ' . $magentoSku);
						$this->logSku($magentoSku, $sapSku, $sapInternalID);
						foreach ($response->Material->Planning->SupplyPlanning as $site) {
							$currentSite = $site->SupplyPlanningAreaID->_;

							if ($currentSite == $this->server) {
								$this->logMessage('Found SupplyPlanningAreaID: ' . $currentSite);
								$qty = $this->getSapAvailabilityData($sapInternalID, $currentSite, $horizon);
								$sapQty += $qty;
								$this->logMessage('Available Quantity for SAP Internal ID: ' . $sapInternalID . ' and Site ID: ' . $currentSite . ' is: ' . $qty);
							}
						}
					} else {
						$this->logMessage('No match found in SAP for Magento Sku: ' . $magentoSku . ' (' . $sapSku . ')');
						$this->logMessage('No SAP skus found for Magento Sku: ' . $magentoSku . ' - product will become out-of-stock');
						$change = self::NO_SAP_SKUS;
					}

				} elseif ($response->ProcessingConditions->ReturnedQueryHitsNumberValue > 1) {

					$matchFound = false;
					foreach ($response->Material as $material) {

						$sapSku = $material->SKU;
						$sapInternalID = $material->InternalID->_;

						if ($magentoSku == $sapSku) {

							$horizon = 'P14D';
							foreach ($response->Material->Sales as $sales) {
								if ($sales->SalesOrganisationID == $this->serverSalesOrganizationId) {
									if ($sales->LifeCycleStatusCode == 3) { // blocked
										$horizon = '999';
									}
								}
							}
							
							$this->logMessage('Match found in SAP for Magento Sku: ' . $magentoSku);
							$this->logSku($magentoSku, $sapSku, $sapInternalID);

							foreach ($material->Planning->SupplyPlanning as $site) {
								$currentSite = $site->SupplyPlanningAreaID->_;

								if ($currentSite == $this->server) {
									$this->logMessage('Found SupplyPlanningAreaID: ' . $currentSite);
									$qty = $this->getSapAvailabilityData($sapInternalID, $currentSite, $horizon);
									$sapQty += $qty;
									$this->logMessage('Available Quantity for SAP Internal ID: ' . $sapInternalID . ' and Site ID: ' . $currentSite . ' is: ' . $qty);
									$matchFound = true;
								}
							}
						} else {
							$this->logMessage('No match found in SAP for Magento Sku: ' . $magentoSku . ' (' . $sapSku . ')');
						}
					}

					if (!$matchFound) {
						$change = self::NO_SAP_SKUS;
					}

				} else {
					$this->logMessage('No SAP skus found for Magento Sku: ' . $magentoSku . ' - product will become out-of-stock');
					$change = self::NO_SAP_SKUS;
				}

				if ($sapQty <= 0) {
					$sapQty = 0;
					$this->logMessage('SAP quantity for Magento Sku: ' . $magentoSku . ' is 0 - product will become out-of-stock');
					$change = self::SAP_OUT_OF_STOCK;
				} else if ($magentoQty == $sapQty) {
					$this->logMessage('Quantity for Magento Sku: ' . $magentoSku . ' and SAP Sku: ' . $sapSku . ' is the same: ' . $magentoQty . ' = ' . $sapQty);
					$change = self::BOTH_EQUAL;
				}
			} else {
				$this->logMessage('Magento Sku: ' . $magentoSku . ' is disabled - no SAP checks performed');
				$change = self::MAGENTO_DISABLED;
			}

			switch ($change) {
				case self::NO_SAP_SKUS:
				case self::SAP_OUT_OF_STOCK:
					if (!$_productStock->getData('is_in_stock')) {
						$change = self::BOTH_OUT_OF_STOCK;
					}
					// update inventory qty
					$_productStock->setData('qty', 0);
					// set out of stock
					$_productStock->setData('is_in_stock', 0);
					$_productStock->save();
					break;
				case self::BOTH_IN_STOCK:
					if (!$_productStock->getData('is_in_stock')) {
						$change = self::WEB_OUT_OF_STOCK;
					}
					// update inventory qty
					$_productStock->setData('qty', $sapQty);
					// set in stock
					$_productStock->setData('is_in_stock', 1);
					$_productStock->save();
					break;
				case self::BOTH_EQUAL:
					$_productStock->setData('is_in_stock', 1);
					$_productStock->save();
					break;
				case self::MAGENTO_DISABLED:
					break;
			}

			$this->logResult($magentoParentSku, $magentoSku, $magentoName, $magentoStatus, $magentoVisibility, $magentoColor, $magentoSize, $discontinued, $magentoQty, $sapQty, $change);

		}

		unset($_product);
		unset($_productStock);
	}

	public function getSapAvailabilityData($sapInternalID, $supplyPlanningAreaID, $horizon)
	{
		$searchCriteria = array(
			'ProductAvailabilityDeterminationQuery' => array (
				'ConsiderScopeOfCheckIndicator' => false,
				'ProductAndSupplyPlanningArea' => array(
					'ProductInternalID' => $sapInternalID,
					'ProductTypeCode' => 1,
					'SupplyPlanningAreaID' => $supplyPlanningAreaID
				),
				'ProductAvailabilityDeterminationHorizonDuration' => $horizon
			)
		);

		$response = $this->availabilityClient->__soapCall('Determine', array($searchCriteria));

		$requirementQuantity = 0;
		$availableQuantity = (int) $response->ProductAvailabilityDeterminationResponse->CurrentStockQuantity->_;
		if (isset($response->ProductAvailabilityDeterminationResponse->RequirementQuantity->_)) {
			$requirementQuantity = (int) $response->ProductAvailabilityDeterminationResponse->RequirementQuantity->_;
		}

		return $availableQuantity - $requirementQuantity;
	}

	private function logMessage($message)
	{
		fwrite($this->logHandle, $message . "\n");
	}

	private function logSku($sku, $sapSku, $sapInternalID)
	{
		$message = 'Magento Sku: ' . $sku . ' | SAP Sku: ' . $sapSku . ' | SAP Internal ID: ' . $sapInternalID;
		fwrite($this->logHandle, $message . "\n");
	}

	private function logResult($magentoParentSku, $magentoSku, $magentoName, $magentoStatus, $magentoVisibility, $magentoColor, $magentoSize, $discontinued, $magentoQty, $sapQty, $change)
	{
		$logRow = [
			$this->server,
			$magentoParentSku,
			$magentoSku,
			$magentoName,
			$magentoStatus,
			$magentoVisibility,
			$magentoColor,
			$magentoSize,
			$discontinued,
			$magentoQty,
			$sapQty,
			$change
		];
		$this->logResults[] = $logRow;
		fwrite($this->logHandle, implode('|', $logRow) . "\n");
	}

	private function sendResultsFile()
	{
		$header = [
			'Server',
			'Config',
			'SKU',
			'Name',
			'Status',
			'Visibility',
			'Color',
			'Size',
			'Discontinued',
			'MagentoBOH',
			'SAPBOH',
			'Change'
		];

		if ($handle = fopen($this->resultsFile, 'w')){
			fputcsv($handle, $header);

			foreach ($this->logResults as $logRow) {
				fputcsv($handle, $logRow);
			}

			fclose($handle);
		}

		$mail = new Zend_Mail('utf-8');
	    $recipients = array(
	        'Test User' => 'testuser@domain.com'
	    );
	    $mailBody = "Please find attached the inventory synchronization report for " . $this->server . " on " . date('m/d/Y');
	    $mail->setBodyHtml($mailBody)
	        ->setSubject('Inventory Synchronization Report')
	        ->addTo($recipients)
	        ->setFrom(Mage::getStoreConfig('trans_email/ident_general/email'), "Test User");

	    $attachment = file_get_contents($this->resultsFile);
	    $mail->createAttachment(
	        $attachment,
	        Zend_Mime::TYPE_TEXT,
	        Zend_Mime::DISPOSITION_ATTACHMENT,
	        Zend_Mime::ENCODING_BASE64,
	        'sap_inventory_results.csv'
	    );

	    try {
	        $mail->send();
	    } catch (Exception $e) {
	        Mage::logException($e);
	    }

	}

    public function configureModule()
    {
        echo "Please configure the module before attempting to run this script: System > Configuration > SAP\n";
    }
}

$shell = new Sap_Inventory_Update();
$shell->run();
