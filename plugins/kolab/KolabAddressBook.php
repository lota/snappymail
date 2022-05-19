<?php

use RainLoop\Providers\AddressBook\Classes\Contact;
use RainLoop\Providers\AddressBook\Classes\Property;
use RainLoop\Providers\AddressBook\Enumerations\PropertyType;

class KolabAddressBook implements \RainLoop\Providers\AddressBook\AddressBookInterface
{
	use \RainLoop\Providers\AddressBook\CardDAV;

	protected
		$oImapClient,
		$sFolderName;

	function __construct(string $sFolderName)
	{
		$metadata = $this->ImapClient()->FolderGetMetadata($sFolderName, [\MailSo\Imap\Enumerations\MetadataKeys::KOLAB_CTYPE]);
		if (!$metadata || 'contact' !== \array_shift($metadata)) {
			$sFolderName = '';
//			throw new \Exception("Invalid kolab contact folder: {$sFolderName}");
		}

		$this->sFolderName = $sFolderName;
	}

	protected function MailClient() : \MailSo\Mail\MailClient
	{
		$oActions = \RainLoop\Api::Actions();
		$oMailClient = $oActions->MailClient();
		if (!$oMailClient->IsLoggined()) {
			$oActions->getAccountFromToken()->IncConnectAndLoginHelper($oActions->Plugins(), $oMailClient, $oActions->Config());
		}
		return $oMailClient;
	}

	protected function ImapClient() : \MailSo\Imap\ImapClient
	{
		if (!$this->oImapClient) {
			$this->oImapClient = $this->MailClient()->ImapClient();
		}
		return $this->oImapClient;
	}

	protected function SelectFolder() : bool
	{
		$sFolderName = $this->sFolderName;
		if ($sFolderName) {
			try {
				$this->ImapClient()->FolderSelect($sFolderName);
				return true;
			} catch (\Throwable $e) {
				\trigger_error("KolabAddressBook {$sFolderName} error: {$e->getMessage()}");
			}
		}
		return false;
	}

	protected function fetchXCardFromMessage(\MailSo\Mail\Message $oMessage) : ?\Sabre\VObject\Component\VCard
	{
		$xCard = null;
		foreach ($oMessage->Attachments() ?: [] as $oAttachment)  {
			if ('application/vcard+xml' === $oAttachment->MimeType()) {
				$result = $this->MailClient()->MessageMimeStream(function ($rResource) use (&$xCard) {
					if (\is_resource($rResource)) {
						$xCard = \Sabre\VObject\Reader::readXML($rResource);
					}
				}, $this->sFolderName, $oMessage->Uid(), $oAttachment->MimeIndex());
				break;
			}
		}
		return $xCard;
	}

	protected function MessageAsContact(\MailSo\Mail\Message $oMessage) : ?Contact
	{
		$oContact = new Contact;
		$oContact->IdContact = $oMessage->Uid();
//		$oContact->Display = isset($aItem['display']) ? (string) $aItem['display'] : '';
		$oContact->Changed = $oMessage->HeaderTimeStampInUTC();

		$oFrom = $oMessage->From();
		if ($oFrom) {
			$oMail = $oFrom[0];
			$oProperty = new Property(PropertyType::EMAIl, $oMail->GetEmail());
			$oContact->Properties[] = $oProperty;
			$oProperty = new Property(PropertyType::FULLNAME, $oMail->GetDisplayName());
//			$oProperty = new Property(PropertyType::FULLNAME, $oMail->ToString());
			$oContact->Properties[] = $oProperty;
//			$oProperty = new Property(PropertyType::NICK_NAME, $oMail->GetDisplayName());
//			$oContact->Properties[] = $oProperty;
		}

		// Fetch xCard attachment and populate $oContact with it
		$xCard = $this->fetchXCardFromMessage($oMessage);
		if ($xCard instanceof \Sabre\VObject\Component\VCard) {
			$oContact->PopulateByVCard($xCard);
		}

		// Reset, else it is 'urn:uuid:01234567-89AB-CDEF-0123-456789ABCDEF'
//		$oContact->IdContactStr = $oMessage->Subject();

		$oContact->UpdateDependentValues();

		return $oContact;
	}

	public function IsSupported() : bool
	{
		// Check $this->ImapClient()->IsSupported('METADATA')
		return true;
	}

	/**
	 * Sync with davClient
	 */
	public function Sync(array $oConfig) : bool
	{
		// TODO
		return false;
	}

	public function Export(string $sEmail, string $sType = 'vcf') : bool
	{
		$bVcf = 'vcf' === $sType;
		$bCsvHeader = true;

		if (!\strlen($this->sFolderName)) {
//			return false;
			throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::CantGetMessageList);
		}

		$this->ImapClient();

		try
		{
			$oParams = new \MailSo\Mail\MessageListParams;
			$oParams->sFolderName = $this->sFolderName;
//			$oParams->iOffset = 0;
			$oParams->iLimit = 999; // Is the max
			$oMessageList = $this->MailClient()->MessageList($oParams);
			foreach ($oMessageList as $oMessage) {
				if ($bVcf) {
					$xCard = $this->fetchXCardFromMessage($oMessage);
					if ($xCard instanceof \Sabre\VObject\Component\VCard) {
						echo $xCard->serialize();
					}
				} else {
					$oContact = $this->MessageAsContact($oMessage);
					if ($oContact) {
						echo $oContact->ToCsv($bCsvHeader);
						$bCsvHeader = false;
					}
				}
			}
		}
		catch (\Throwable $oException)
		{
			throw $oException;
			throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::CantGetMessageList, $oException);
		}
		return true;
	}

	public function ContactSave(string $sEmail, Contact $oContact) : bool
	{
		// TODO
//		$emails = $oContact->GetEmails();

		if (!$this->SelectFolder()) {
			return false;
		}

		$oContact->PopulateDisplayAndFullNameValue();

		$iUID = $oContact->IdContact;

		$oPrevMessage = $this->MailClient()->Message($this->sFolderName, $iUID);
		if ($oPrevMessage) {
			$oVCard = $this->fetchXCardFromMessage($oPrevMessage);
		} else {
			$oVCard = null;
			$iUID = 0;
		}

		$oMessage = new \MailSo\Mime\Message();

		$sEmail = '';
		if (isset($oVCard->EMAIL)) {
			foreach ($oVCard->EMAIL as $oProp) {
				$oTypes = $oProp ? $oProp['TYPE'] : null;
				$sValue = $oProp ? \trim($oProp->getValue()) : '';
				if ($sValue && (!$sEmail || ($oTypes && $oTypes->has('PREF')))) {
					$sEmail = $sValue;
				}
			}
			if ($sEmail) {
				$oMessage->SetFrom(new \MailSo\Mime\Email($sEmail, $oContact->Display));
			}
		}

		$oMessage->SetSubject($oContact->GetUID());
//		$oMessage->SetDate(\time());
		$oMessage->Headers->AddByName('X-Kolab-Type', 'application/x-vnd.kolab.contact');
		$oMessage->Headers->AddByName('X-Kolab-Mime-Version', '3.0');
//		$oMessage->Headers->AddByName('User-Agent', 'SnappyMail');

		$oPart = new \MailSo\Mime\Part;
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/plain');
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, '7Bit');
		$oPart->Body = "This is a Kolab Groupware object.\r\n"
			. "To view this object you will need an email client that can understand the Kolab Groupware format.\r\n"
			. "For a list of such email clients please visit\r\n"
			. "http://www.kolab.org/get-kolab";
		$oMessage->SubParts->append($oPart);

		// Now the vCard
		$oPart = new \MailSo\Mime\Part;
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'application/vcard+xml; name="kolab.xml"');
//		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
		$oPart->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_DISPOSITION, 'attachment; filename="kolab.xml"');
		$oPart->Body = $oContact->ToXCard($oVCard/*, $oLogger*/);
		$oMessage->SubParts->append($oPart);

		// Store Message
		$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();
		$iMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter(
			$oMessage->ToStream(false), array($rMessageStream), 8192, true, true);
		if (false !== $iMessageStreamSize) {
			\rewind($rMessageStream);
			$this->ImapClient()->MessageReplaceStream($this->sFolderName, $iUID, $rMessageStream, $iMessageStreamSize);
		}

		return true;
	}

	public function DeleteContacts(string $sEmail, array $aContactIds) : bool
	{
		try {
			$this->MailClient()->MessageDelete(
				$this->sFolderName,
				new \MailSo\Imap\SequenceSet($aContactIds)
			);
			return true;
		} catch (\Throwable $e) {
		}
		return false;
	}

	public function DeleteAllContacts(string $sEmail) : bool
	{
		// Called by \RainLoop\Api::ClearUserData()
		// Not needed as the contacts are inside IMAP mailbox
//		$this->MailClient()->FolderClear($this->sFolderName);
		return false;
	}

	public function GetContacts(string $sEmail, int $iOffset = 0, int $iLimit = 20, string $sSearch = '', int &$iResultCount = 0) : array
	{
		if (!\strlen($this->sFolderName)) {
//			return [];
			throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::CantGetMessageList);
		}

		$this->ImapClient();

		$aResult = [];

		try
		{
			$oParams = new \MailSo\Mail\MessageListParams;
			$oParams->sFolderName = $this->sFolderName;
			$oParams->iOffset = $iOffset;
			$oParams->iLimit = $iLimit;
			if ($sSearch) {
				$oParams->sSearch = 'from='.$sSearch;
			}
			$oParams->sSort = 'FROM';
//			$oParams->iPrevUidNext = $this->GetActionParam('UidNext', 0);
//			$oParams->bUseThreads = false;

			$oMessageList = $this->MailClient()->MessageList($oParams);
			foreach ($oMessageList as $oMessage) {
				$aResult[] = $this->MessageAsContact($oMessage);
			}
		}
		catch (\Throwable $oException)
		{
			throw $oException;
			throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::CantGetMessageList, $oException);
		}

		return $aResult;
	}

	public function GetContactByID(string $sEmail, $mID, bool $bIsStrID = false) : ?Contact
	{
		if ($bIsStrID) {
			$oMessage = null;
		} else {
			$oMessage = $this->MailClient()->Message($this->sFolderName, $mID);
		}
		return $oMessage ? $this->MessageAsContact($oMessage) : null;
	}

	public function GetSuggestions(string $sEmail, string $sSearch, int $iLimit = 20) : array
	{
		$sSearch = \trim($sSearch);
		if (2 > \strlen($sSearch) || !$this->SelectFolder()) {
			return [];
		}

		$sSearch = \MailSo\Imap\SearchCriterias::escapeSearchString($this->ImapClient(), $sSearch);
		$aUids = \array_slice(
			$this->ImapClient()->MessageSimpleSearch("FROM {$sSearch}"),
			0, $iLimit
		);

		$aResult = [];
		foreach ($this->ImapClient()->Fetch(['BODY.PEEK[HEADER.FIELDS (FROM)]'], \implode(',', $aUids), true) as $oFetchResponse) {
			$oHeaders = new \MailSo\Mime\HeaderCollection($oFetchResponse->GetHeaderFieldsValue());
			$oFrom = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::FROM_, true);
			foreach ($oFrom as $oMail) {
				$aResult[] = [$oMail->GetEmail(), $oMail->GetDisplayName()];
			}
		}

		return $aResult;
	}

	public function IncFrec(string $sEmail, array $aEmails, bool $bCreateAuto = true) : bool
	{
		return false;
	}

	public function Test() : string
	{
		$sResult = '';
		try
		{
//			$sResult = 'Unknown error';
		}
		catch (\Throwable $oException)
		{
			$sResult = $oException->getMessage();
			if (!\is_string($sResult) || empty($sResult)) {
				$sResult = 'Unknown error';
			}
		}

		return $sResult;
	}
}