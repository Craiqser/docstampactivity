<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$arActivityDescription = array(
	'NAME' => GetMessage('DOCSTAMP_NAME'),
	'DESCRIPTION' => GetMessage('DOCSTAMP_DESCRIPTION'),
	'TYPE' => 'activity',
	'CLASS' => 'DocStampActivity',
	'JSCLASS' => 'BizProcActivity',
	'CATEGORY' => array(
		'ID' => 'other'
	),
	'RETURN' => array(
		'ObjectId' => array(
			'NAME' => GetMessage('DOCSTAMP_RET_OBJECT_ID'),
			'TYPE' => 'int',
		),
		'DetailUrl' => array(
			'NAME' => GetMessage('DOCSTAMP_RET_DETAIL_URL'),
			'TYPE' => 'string',
		),
		'DownloadUrl' => array(
			'NAME' => GetMessage('DOCSTAMP_RET_DOWNLOAD_URL'),
			'TYPE' => 'string',
		),
	)
);
?>
