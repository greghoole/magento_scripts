<?php

require_once '../abstract.php';

class Sap_Credit_Limit_Update extends Mage_Shell_Abstract
{
	private $logFile = '../../var/log/sap_credit_limit.log';
	private $logHandle;
	private $logResults = [];

	private $resultsFile;

	private $wsdlFiles = [
		'customer' => 'wsdl/QueryCustomerIn.wsdl',
		'accounts' => 'wsdl/QueryAccountOpenAmounts.wsdl'
	];

	private $credentials = [
		'login' => 'USERNAME',
		'password' => 'PASSWORD',
		'trace' => 1
	];

	private $server;

	public function __construct()
	{
		parent::__construct();

		$this->customerClient = new SoapClient($this->wsdlFiles['customer'], $this->credentials);
		$this->accountsClient = new SoapClient($this->wsdlFiles['accounts'], $this->credentials);
		
		$this->resultsFile = Mage::getBaseDir() . '/var/log/sap_credit_limit_results.csv';

		$this->logHandle = fopen($this->logFile, 'w');

		$resource = Mage::getSingleton('core/resource');
		$this->readConnection = $resource->getConnection('core_read');
		$this->writeConnection = $resource->getConnection('core_write');
	}

	public function run()
	{
		$runNow = false;

		Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

		$enabled = Mage::getStoreConfig('testuser_sap_options/testuser_sap_cl_settings_group/enable_credit_limit');
		if ($enabled) {

			// check if it is time to run
			$scheduleParts1 = explode(',', Mage::getStoreConfig('testuser_sap_options/testuser_sap_cl_settings_group/credit_limit_schedule'));
			$schedule1 = $scheduleParts1[0] . ':' . $scheduleParts1[1];
			$scheduleParts2 = explode(',', Mage::getStoreConfig('testuser_sap_options/testuser_sap_cl_settings_group/credit_limit_schedule_1'));
			$schedule2 = $scheduleParts2[0] . ':' . $scheduleParts2[1];
			$scheduleParts3 = explode(',', Mage::getStoreConfig('testuser_sap_options/testuser_sap_cl_settings_group/credit_limit_schedule_2'));
			$schedule3 = $scheduleParts3[0] . ':' . $scheduleParts3[1];
			$scheduleParts4 = explode(',', Mage::getStoreConfig('testuser_sap_options/testuser_sap_cl_settings_group/credit_limit_schedule_3'));
			$schedule4 = $scheduleParts4[0] . ':' . $scheduleParts4[1];

			if (file_exists(Mage::getBaseDir() . '/shell/sap/run_cl.flag')) { 
				unlink(Mage::getBaseDir() . '/shell/sap/run_cl.flag');
				$runNow = true;
			} elseif ( ($schedule1 == date('H:i')) || ($schedule2 == date('H:i')) || ($schedule3 == date('H:i')) || ($schedule4 == date('H:i'))) {
				$runNow = true;
			}

			if ($runNow) {
				$startTime = 'Start Time: ' . date('m/d/Y h:i:s a') . "\n";
				$this->logMessage($startTime);
				echo $startTime;

				$query = "SELECT email FROM customer_entity WHERE is_active = '1' AND website_id IN (8, 9, 10)";
				$results = $this->readConnection->fetchAll($query);

				foreach ($results as $row) {
					$this->getSapCreditLimitData($row['email']);
				}

				$this->sendResultsFile();
				
				$endTime = 'End Time: ' . date('m/d/Y h:i:s a') . "\n";
				$this->logMessage($endTime);
				echo $endTime;
			}
		}
	}

	public function getSapCreditLimitData($customerEmail)
	{
		$matchFound = false;

		$searchCriteria = array(
			'CustomerSelectionByCommunicationData' => array(
				'SelectionByEmailURI' => array(
					'InclusionExclusionCode' => 'I',
					'IntervalBoundaryTypeCode' => '1',
					'LowerBoundaryEmailURI' => trim($customerEmail)
				)
			),
			'ProcessingConditions' => array(
				'QueryHitsMaximumNumberValue' => 1000,
				'QueryHitsUnlimitedIndicator' => false
			)
		);

		$response = $this->customerClient->__soapCall('FindByCommunicationData', array($searchCriteria));

		if ($response->ProcessingConditions->ReturnedQueryHitsNumberValue == 1) {

			$matchFound = true;
			$accountNumber = $response->Customer->InternalID;
			$creditLimitData = $this->getSapOpenAmountsData($accountNumber);

		} elseif ($response->ProcessingConditions->ReturnedQueryHitsNumberValue > 1) {

			foreach ($response->Customer as $customer) {
				foreach ($customer->AddressInformation as $addressInfo) {
					
					if ($addressInfo->AddressUsage->AddressUsageCode->_ == 'XXDEFAULT' && $addressInfo->Address->EmailURI->_ == $customerEmail) {
						$matchFound = true;
						$accountNumber = $customer->InternalID;
						$creditLimitData = $this->getSapOpenAmountsData($accountNumber);						
					}
				}
			}

		} else {
			$this->logMessage('No SAP Account Data found for : ' . $customerEmail);
		}

		if ($matchFound) {
			if (empty($creditLimitData)) {
				$this->logMessage('No SAP Credit Limit Data found for : ' . $customerEmail);
			} else {
				$this->logResult($customerEmail, $creditLimitData);

				$query = "REPLACE INTO testuser_credit_limit (customer_email, authorized_limit, used_limit, open_limit, delivery_block) VALUES ('" . $customerEmail . "', '" . $creditLimitData['authorized_limit'] . "', '" . $creditLimitData['used_limit'] . "', '" . $creditLimitData['open_limit'] . "', '" . (int) $creditLimitData['delivery_block'] . "')";
				$this->writeConnection->query($query);
			}
		}
	}

	public function getSapOpenAmountsData($accountNumber)
	{
		$creditLimitData = array(
			'authorized_limit' => 0,
			'used_limit' => 0,
			'open_limit' => 0,
			'delivery_block' => false
		);

		$searchCriteria = array(
			'AccountOpenAmountsSelection' => array(
				'SelectionByAccountID' => array(
					'InclusionExclusionCode' => 'I',
					'IntervalBoundaryTypeCode' => '1',
					'LowerBoundaryIdentifier' => $accountNumber
				)
			),
			'ProcessingConditions' => array(
				'QueryHitsMaximumNumberValue' => 1000,
				'QueryHitsUnlimitedIndicator' => false
			)
		);

		$response = $this->accountsClient->__soapCall('FindAccountOpenAmounts', array($searchCriteria));

		if ($response->ProcessingConditions->ReturnedQueryHitsNumberValue == 1) {

			// authorized limit
			if (isset($response->AccountOpenAmounts->CreditLimitAmount->_)) {
				$creditLimitData['authorized_limit'] = $response->AccountOpenAmounts->CreditLimitAmount->_;
			}

			// used limit
			if (isset($response->AccountOpenAmounts->ReceivablesBalanceAmount->_)) {
				$creditLimitData['used_limit'] += ($response->AccountOpenAmounts->ReceivablesBalanceAmount->_ < 0) ? 0 : $response->AccountOpenAmounts->ReceivablesBalanceAmount->_; 
			}
			if (isset($response->AccountOpenAmounts->SalesOrderAmount->_)) {
				$creditLimitData['used_limit'] += ($response->AccountOpenAmounts->SalesOrderAmount->_ < 0) ? 0 : $response->AccountOpenAmounts->SalesOrderAmount->_;
			}
			if (isset($response->AccountOpenAmounts->ServiceOrderAmount->_)) {
				$creditLimitData['used_limit'] += ($response->AccountOpenAmounts->ServiceOrderAmount->_ < 0) ? 0 : $response->AccountOpenAmounts->ServiceOrderAmount->_;
			}

			// open limit
			$creditLimitData['open_limit'] = $creditLimitData['authorized_limit'] - $creditLimitData['used_limit'];
			
			// if (isset($response->AccountOpenAmounts->CurrentSpendingLimitAmount->_)) {
			// 	$creditLimitData['open_limit'] = $response->AccountOpenAmounts->CurrentSpendingLimitAmount->_;
			// }

			// delivery block
			if (isset($response->AccountOpenAmounts->DeliveryBlockIndicator)) {
				$creditLimitData['delivery_block'] = (bool) $response->AccountOpenAmounts->DeliveryBlockIndicator;
			}

		}

		return $creditLimitData;
	}

	private function logMessage($message)
	{
		fwrite($this->logHandle, $message . "\n");
	}

	private function logResult($customerEmail, $creditLimitData)
	{
		$logRow = [
			$customerEmail,
			$creditLimitData['authorized_limit'],
			$creditLimitData['used_limit'],
			$creditLimitData['open_limit'],
			(bool) $creditLimitData['delivery_block']
		];
		$this->logResults[] = $logRow;
		fwrite($this->logHandle, implode('|', $logRow) . "\n");
	}

	private function sendResultsFile()
	{
		$header = [
			'Customer',
			'Authorized Credit Limit',
			'Used Credit Limit',
			'Open Credit Limit',
			'Delivery Block'
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

	    $server = '';
	    if (gethostname() == 'domain.com') {
	    	$server = 'Detroit';
	    } elseif (gethostname() == 'domain.net') {
	    	$server = 'London';
	    }

	    $mailBody = "Please find attached the credit limit synchronization report for the " . $server . " server on " . date('m/d/Y');
	    $mail->setBodyHtml($mailBody)
	        ->setSubject('Credit Limit Synchronization Report - ' . $server)
	        ->addTo($recipients)
	        ->setFrom(Mage::getStoreConfig('trans_email/ident_general/email'), "Test User");

	    $attachment = file_get_contents($this->resultsFile);
	    $mail->createAttachment(
	        $attachment,
	        Zend_Mime::TYPE_TEXT,
	        Zend_Mime::DISPOSITION_ATTACHMENT,
	        Zend_Mime::ENCODING_BASE64,
	        'sap_credit_limit_results.csv'
	    );

	    try {
	        $mail->send();
	    } catch (Exception $e) {
	        Mage::logException($e);
	    }

	}
}

$shell = new Sap_Credit_Limit_Update();
$shell->run();
