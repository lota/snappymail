<?php

namespace RainLoop;

class Settings
{
	/**
	 * @var array
	 */
	protected $aData;

	public function __construct()
	{
		$this->aData = array();
	}

	public function InitData(array $aData) : void
	{
		$this->aData = $aData;
	}

	public function DataAsArray() : array
	{
		return $this->aData;
	}

	/**
	 * @param mixed $mDefValue = null
	 *
	 * @return mixed
	 */
	public function GetConf(string $sName, $mDefValue = null)
	{
		return isset($this->aData[$sName]) ? $this->aData[$sName] : $mDefValue;
	}

	/**
	 * @param mixed $mValue
	 */
	public function SetConf(string $sName, $mValue) : void
	{
		$this->aData[$sName] = $mValue;
	}
}
