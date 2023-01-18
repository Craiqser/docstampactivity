<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

class CBPDocStampActivity extends CBPActivity
{
	const EXTENSIONS_ALLOWED = ['doc', 'docx', 'pdf', 'xls', 'xlsx'];
	const TEMPLATE_PATH = '/templates/stamp1.pdf';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = array(
			'Title' => '',
			'EntityType' => '',
			'EntityId' => '',
			'SourceFile' => 0,
			'CreatedBy' => null,

			// return properties
			'ObjectId' => null,
			'DetailUrl' => null,
			'DownloadUrl' => null,
		);

		// return properties mapping
		$this->SetPropertiesTypes(array(
			'ObjectId' => array(
				'Type' => 'int',
				'Multiple' => true,
			),
			'DetailUrl' => array(
				'Type' => 'string',
				'Multiple' => true,
			),
			'DownloadUrl' => array(
				'Type' => 'string',
				'Multiple' => true,
			),
		));
	}

	protected function ReInitialize()
	{
		parent::ReInitialize();

		$this->ObjectId = null;
		$this->DetailUrl = null;
		$this->DownloadUrl = null;
	}

	private function getTargetFolder($entityType, $entityId)
	{
		if ($entityType == 'folder')
		{
			return \Bitrix\Disk\Folder::loadById($entityId);
		}

		$rootActivity = $this->GetRootActivity();
		$documentId = $rootActivity->GetDocumentId();

		switch ($entityType)
		{
			case 'user':
				$entityType = \Bitrix\Disk\ProxyType\User::className();
				$entityId = CBPHelper::ExtractUsers($entityId, $documentId, true);
				break;
			case 'common':
				$entityType = \Bitrix\Disk\ProxyType\Common::className();
				break;
			default:
				$entityType = null;
		}

		if ($entityType)
		{
			$storage = \Bitrix\Disk\Storage::load(array(
				'=ENTITY_ID' => $entityId,
				'=ENTITY_TYPE' => $entityType,
			));

			if ($storage) {
				return $storage->getRootObject();
			}
		}

		return false;
	}

	/*
	Конвертация в pdf и наложение печати из шаблона.
	$path = 'C:/OSPanel/domains/bx24.local/upload/disk/b4f/b4fe1895ae4d5be755d0704544a8ad38';
	$name = 'input.doc';
	*/
	private function fileProcess($path, $name)
	{
		$ret = '';

		// Проверка файла и его расширения.
		if (!file_exists($path)) {
			$this->WriteToTrackingService('Файл ' . $path . ' не найден.');
			return $ret;
		}

		$fn_ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

		if (!in_array($fn_ext, self::EXTENSIONS_ALLOWED)) {
			$this->WriteToTrackingService('Некорректное расширение файла: ' . $fn_ext);
			return $ret;
		}

		// Восстанавливаем файл во временную директорию.
		$tmp_fn = $_SERVER['DOCUMENT_ROOT'].'/upload/tmp/'.$name;
		unlink($tmp_fn);
		copy($path, $tmp_fn);

		if (!file_exists($tmp_fn)) {
			$this->WriteToTrackingService('Не удалось восстановить файл '.$tmp_fn);
			return $ret;
		}

		$pdf_fn_in = ''; // Файл, на который будет наложена печать.
		$pdf_fn_out = ''; // Результат после обработки исходного файла.

		// Если расширение исходного файла не pdf, то конвертируем в pdf с помощью LibreOffice.
		if ($fn_ext !== 'pdf')
		{
			$info = pathinfo($tmp_fn);
			$pdf_fn_in = $info['dirname'].'/'.$info['filename'].'.pdf';
			unlink($pdf_fn_in);

			try
			{
				// $cmd = '"c:/Program Files (x86)/LibreOffice/program/soffice.exe" --headless --convert-to pdf:writer_pdf_Export '.$tmp_fn.' --outdir '.$info['dirname'];
				$cmd = 'soffice --headless --convert-to pdf:writer_pdf_Export '.$tmp_fn.' --outdir '.$info['dirname'];
				$res = exec($cmd);
			}
			catch (Exception $e)
			{
				$this->WriteToTrackingService('Ошибка конвертации. Команда: '.$cmd.'. Исключение: '.$e->getMessage());
			}

			unlink($tmp_fn);

			if (!file_exists($pdf_fn_in)) {
				$this->WriteToTrackingService('Файл '.$pdf_fn_in.' не найден.');
				return $ret;
			}
		} else {
			$pdf_fn_in = $tmp_fn;
		}

		$info = pathinfo($pdf_fn_in);
		$pdf_fn_out = $info['dirname'].'/'.$info['filename'].'_fin.pdf';
		unlink($pdf_fn_out);
		$template_path = $_SERVER['DOCUMENT_ROOT'].'/upload'.self::TEMPLATE_PATH; // Файл-шаблон.

		try
		{
			// $cmd = '"D:/Downloads/B24/Stamp/cpdf.exe" -stamp-on "'.$template_path.'" "'.$pdf_fn_in.'" -o "'.$pdf_fn_out.'"';
			$cmd = '/opt/cpdf/cpdf -stamp-on "'.$template_path.'" "'.$pdf_fn_in.'" -o "'.$pdf_fn_out.'"';
			$res = exec($cmd);
		}
		catch (Exception $e)
		{
			$this->WriteToTrackingService('Ошибка cpdf. Команда: '.$cmd.'. Исключение: '.$e->getMessage());
		}

		unlink($pdf_fn_in);

		if (file_exists($pdf_fn_out)) {
			$ret = $pdf_fn_out;
		} else {
			$this->WriteToTrackingService('Файл '.$pdf_fn_out.' не найден.');
		}

		return $ret;
	}

	public function Execute()
	{
		if (!CModule::IncludeModule("disk"))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$folder = $this->getTargetFolder($this->EntityType, $this->EntityId);

		if (!$folder)
		{
			$this->WriteToTrackingService(GetMessage('DOCSTAMP_TARGET_ERROR'));
			return CBPActivityExecutionStatus::Closed;
		}

		$files = (array) $this->ParseValue($this->getRawProperty('SourceFile'), 'file');
		$urlManager = \Bitrix\Disk\Driver::getInstance()->getUrlManager();
		$objectIds = $detailUrls = $downloadUrls = array();
		$createdBy = CBPHelper::ExtractUsers($this->CreatedBy, $this->GetDocumentId(), true);

		if (!$createdBy) {
			$createdBy = \Bitrix\Disk\SystemUser::SYSTEM_USER_ID;
		}

		foreach ($files as $file)
		{
			$file = (int)$file;
			$fileArray = CFile::MakeFileArray($file);

			if (!is_array($fileArray))
			{
				$this->WriteToTrackingService(GetMessage('DOCSTAMP_SOURCE_ERROR'));
				continue;
			}

			$res = $this->fileProcess($fileArray['tmp_name'], $fileArray['name']);

			if ($res)
			{
				unset($fileArray);
				$fileArray = CFile::MakeFileArray($res);

				if (!is_array($fileArray))
				{
					$this->WriteToTrackingService(GetMessage('DOCSTAMP_SOURCE_ERROR'));
					unlink($res);
					continue;
				}
 
				$uploadedFile = $folder->uploadFile($fileArray, array(
					'NAME' => $fileArray['name'],
					'CREATED_BY' => $createdBy
					), array(), true
				);

				if ($uploadedFile)
				{
					$objectIds[] = $uploadedFile->getId();
					$downloadUrls[] = $urlManager->getUrlForDownloadFile($uploadedFile, true);
					$detailUrls[] = $urlManager->encodeUrn($urlManager->getHostUrl().$urlManager->getPathFileDetail($uploadedFile));
				}
				else
				{
					$this->WriteToTrackingService(GetMessage('DOCSTAMP_UPLOAD_ERROR'));
				}

				unlink($res);
			}
		}

		$this->ObjectId = $objectIds;
		$this->DownloadUrl = $downloadUrls;
		$this->DetailUrl = $detailUrls;

		return CBPActivityExecutionStatus::Closed;
	}

	public static function ValidateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = array();

		if ($user && !$user->isAdmin())
		{
			$arErrors[] = array(
				"code" => "AccessDenied",
				"parameter" => "Admin",
				"message" => GetMessage("DOCSTAMP_ACCESS_DENIED")
			);
		}

		if (empty($arTestProperties['EntityType'])) {
			$arErrors[] = array("code" => "NotExist", "parameter" => "EntityType", "message" => GetMessage("DOCSTAMP_EMPTY_ENTITY_TYPE"));
		}

		if (empty($arTestProperties['EntityId'])) {
			$arErrors[] = array("code" => "NotExist", "parameter" => "EntityId", "message" => GetMessage("DOCSTAMP_EMPTY_ENTITY_ID"));
		}

		if (empty($arTestProperties['SourceFile'])) {
			$arErrors[] = array("code" => "NotExist", "parameter" => "SourceFile", "message" => GetMessage("DOCSTAMP_EMPTY_SOURCE_FILE"));
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	// Получение значений.
	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $currentValues = null, $formName = "")
	{
		if (!CModule::IncludeModule("disk")) {
			return '';
		}

		$runtime = CBPRuntime::GetRuntime();

		$arMap = array(
			'EntityType' => 'entity_type',
			'EntityId' => 'entity_id',
			'SourceFile' => 'source_file',
			'CreatedBy'	=> 'created_by'
		);

		if (!is_array($currentValues))
		{
			$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);

			foreach ($arMap as $k => $v)
			{
				$currentValues[$arMap[$k]] = isset($arCurrentActivity['Properties'][$k]) ? $arCurrentActivity['Properties'][$k] : '';
			}
		}

		if (empty($currentValues['entity_type'])) {
			$currentValues['entity_type'] = 'user';
		}

		if (!empty($currentValues['entity_id_'.$currentValues['entity_type']])) {
			$currentValues['entity_id'] = $currentValues['entity_id_'.$currentValues['entity_type']];
		}

		if (empty($currentValues['entity_id'])
			&& isset($currentValues['entity_id_'.$currentValues['entity_type'].'_x'])
			&& CBPDocument::IsExpression($currentValues['entity_id_'.$currentValues['entity_type'].'_x']))
		{
			$currentValues['entity_id'] = $currentValues['entity_id_'.$currentValues['entity_type'].'_x'];
		}

		if (($currentValues['entity_type'] == 'user')
			&& !CBPDocument::IsExpression($currentValues['entity_id']))
		{
			$currentValues['entity_id'] = CBPHelper::UsersArrayToString($currentValues['entity_id'], $arWorkflowTemplate, $documentType);
		}

		if (!CBPDocument::IsExpression($currentValues['created_by'])) {
			$currentValues['created_by'] = CBPHelper::UsersArrayToString($currentValues['created_by'], $arWorkflowTemplate, $documentType);
		}

		return $runtime->ExecuteResourceFile(__FILE__, 'properties_dialog.php',
			array(
				'arCurrentValues' => $currentValues,
				'formName' => $formName,
			)
		);
	}

	// Запись значений.
	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $currentValues, &$arErrors)
	{
		$arErrors = array();

		$arMap = array(
			'entity_type' => 'EntityType',
			'source_file' => 'SourceFile',
			'created_by' => 'CreatedBy',
		);

		$arProperties = array('EntityId' => '');

		foreach ($arMap as $key => $value)
		{
			$arProperties[$value] = $currentValues[$key];
		}

		if (in_array($arProperties['EntityType'], array('user', 'common', 'folder'))) {
			$arProperties['EntityId'] = $currentValues['entity_id_'.$arProperties['EntityType']];
		}

		if (empty($arProperties['EntityId'])
			&& isset($currentValues['entity_id_'.$arProperties['EntityType'].'_x'])
			&& CBPDocument::IsExpression($currentValues['entity_id_'.$arProperties['EntityType'].'_x']))
		{
			$arProperties['EntityId'] = $currentValues['entity_id_'.$arProperties['EntityType'].'_x'];
		}

		if (($arProperties['EntityType'] == 'user')
			&& !CBPDocument::IsExpression($arProperties['EntityId']))
		{
			$arProperties['EntityId'] = CBPHelper::UsersStringToArray($arProperties['EntityId'], $documentType, $arErrors);
		}

		if (!CBPDocument::IsExpression($arProperties['CreatedBy'])) {
			$arProperties['CreatedBy'] = CBPHelper::UsersStringToArray($arProperties['CreatedBy'], $documentType, $arErrors);
		}

		if (count($arErrors) > 0) {
			return false;
		}

		$arErrors = self::ValidateProperties($arProperties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));

		if (count($arErrors) > 0) {
			return false;
		}

		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity['Properties'] = $arProperties;

		return true;
	}
}
?>
