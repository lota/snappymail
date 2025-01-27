<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Cache\Drivers;

/**
 * @category MailSo
 * @package Cache
 * @subpackage Drivers
 */
class File implements \MailSo\Cache\DriverInterface
{
	/**
	 * @var string
	 */
	private $sCacheFolder;

	/**
	 * @var string
	 */
	private $sKeyPrefix;

	function __construct(string $sCacheFolder, string $sKeyPrefix = '')
	{
		$this->sCacheFolder = $sCacheFolder;
		$this->sCacheFolder = rtrim(trim($this->sCacheFolder), '\\/').'/';

		$this->sKeyPrefix = $sKeyPrefix;
		if (!empty($this->sKeyPrefix))
		{
			$this->sKeyPrefix = \str_pad(\preg_replace('/[^a-zA-Z0-9_]/', '_',
				rtrim(trim($this->sKeyPrefix), '\\/')), 5, '_');

			$this->sKeyPrefix = '__/'.
				\substr($this->sKeyPrefix, 0, 2).'/'.\substr($this->sKeyPrefix, 2, 2).'/'.
				$this->sKeyPrefix.'/';
		}
	}

	public function Set(string $sKey, string $sValue) : bool
	{
		$sPath = $this->generateCachedFileName($sKey, true);
		return '' === $sPath ? false : false !== \file_put_contents($sPath, $sValue);
	}

	public function Get(string $sKey) : string
	{
		$sValue = '';
		$sPath = $this->generateCachedFileName($sKey);
		if ('' !== $sPath && \file_exists($sPath))
		{
			$sValue = \file_get_contents($sPath);
		}

		return \is_string($sValue) ? $sValue : '';
	}

	public function Delete(string $sKey) : void
	{
		$sPath = $this->generateCachedFileName($sKey);
		if ('' !== $sPath && \file_exists($sPath))
		{
			\unlink($sPath);
		}
	}

	public function GC(int $iTimeToClearInHours = 24) : bool
	{
		if (0 < $iTimeToClearInHours)
		{
			\MailSo\Base\Utils::RecTimeDirRemove($this->sCacheFolder, 3600 * $iTimeToClearInHours);
			return true;
		}

		return false;
	}

	private function generateCachedFileName(string $sKey, bool $bMkDir = false) : string
	{
		$sFilePath = '';
		if (3 < \strlen($sKey))
		{
			$sKeyPath = \sha1($sKey);
			$sKeyPath = \substr($sKeyPath, 0, 2).'/'.\substr($sKeyPath, 2, 2).'/'.$sKeyPath;

			$sFilePath = $this->sCacheFolder.$this->sKeyPrefix.$sKeyPath;
			$dir = \dirname($sFilePath);
			if ($bMkDir && !\is_dir($dir) && !\mkdir($dir, 0700, true))
			{
				$sFilePath = '';
			}
		}

		return $sFilePath;
	}
}
