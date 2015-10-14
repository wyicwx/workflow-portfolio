<?php
/**
 * Name: 		Stock
 * Author: 		weixinchen(weixinchen@tencent.com)
 * Revised: 	13/10/2015
 * Version:		0.1
 */
require_once("workflows.php");
require_once("filecache.php");

class SmartBox extends Workflows
{
	private $keyword = "";
	private $queryUrl = "http://smartbox.gtimg.cn/s3/?&t=all&format=jsonp&q=";

	function setKeyWord($keyword) {
		$this->keyword = $keyword;
	}

	function search() {
		$cacheData = FileCache::get('__cache__'.$this->keyword);

		if(!$cacheData) {
			$url = $this->queryUrl.urlencode($this->keyword);

			$request_result = $this->request($url);
			$json = json_decode($request_result);
			$searchData = $json->data;
			if(count($searchData) > 0) {
				FileCache::set('__cache__'.$this->keyword, $searchData, 24*60*60);
			}
		} else {
			$searchData = $cacheData;
		}

		if(count($searchData) > 0) {
			
			$codeArray = array();

			foreach ($searchData as $value) {
				$d = explode('~', $value);
				if(preg_match('/(\..*)$/', $d[1], $re)) {
					$d[1] = str_replace($re[1], "", $d[1]);
				}
				if($d[0] == 'us') {
					$d[1] = strtoupper($d[1]);
				}
				$dCode = $d[0].$d[1];
				if($d[0] == 'hk') {
					$dCode = 'r_'.$dCode;
				}
				array_push($codeArray, $dCode);
			}

			$qt = new StockQt();
			$qt->getQt(implode(',', $codeArray));

			foreach ($searchData as $key => $value) {
				$stock = new Stock($value, $qt);
				$this->result($key, $stock->getLink(), $stock->getTitle(), $stock->getSubTitle(), null);
			}
		} else {
			$this->lastPlaceholder();
		}
	}

	function lastPlaceholder() {
		$this->result(0, 'http://gu.qq.com/i', '没有找到股票？进入我的自选股查找', null, null);		
	}
}

class Stock
{
	// 市场: sh|sz|hk|us|jj
	public $market;
	// 市场类类别:
	public $typeName;
	// 代码
	public $code;
	// 详细代码
	public $fullCode;
	// 名称
	public $name;
	// 拼音
	public $pinyin;
	// 类别
	public $category;

	private $qt;

	function __construct($data, $qt) {
		$result = explode("~", $data);
		if($result[0] == 'us') {
			if(preg_match('/(\..*)$/', $result[1], $re)) {
				$result[1] = str_replace($re[1], "", $result[1]);
			}
			$result[1] = strtoupper($result[1]);
		}
		$this->market = $result[0];
		$this->code = $result[1];
		$this->fullCode = $this->market.$this->code;
		$this->name = $result[2];
		$this->pinyin = $result[3];
		$this->category = $result[4];
		if($qt->getItem($this->fullCode)) {
			$this->qt = new StockQt($qt->getItem($this->fullCode));
		}

		$this->parse();
	}

	private function parse() {
		if($this->category == 'QH-QH') {
			$this->typeName = '期货';
		} else if($this->category == 'QH-IF') {
			$this->typeName = '股期';
		} else if($this->market == 'us') {
			$this->typeName = '美股';
		} else if($this->market == 'hk') {
			$this->typeName = '港股';
		} else if($this->market == 'jj') {
			$this->typeName = '基金';
		} else if($this->market == 'sh' || $this->market == 'sz') {
			switch($this->category) {
				case 'FJ':
				case 'LOF':
				case 'ETF':
					$this->typeName = '基金';
				break;
				case 'ZS':
				case 'GP-A':
				case 'GP-B':
				case 'ZQ':
				case 'QZ':
				default:
					if($this->market == 'sh') {
						$this->typeName = '上海';
					} else {
						$this->typeName = '深圳';
					}
				break;
			}
		} else {
			$this->typeName = '未知';
		}
	}

	function getTitle() {
		$typeName = $this->typeName;
		$name = $this->name;
		$code = $this->code;

		$return = sprintf("[%s] %-16s %-12s", $typeName, $name, $code);
		if($this->qt) {
			if(!$this->qt->getErrorStatus()) {
				$price = $this->qt->getPrice();
				$percent = $this->qt->getPercent();

				$return .= sprintf(" %-12s %-12s", $price, $percent);
			} else {
				$status = $this->qt->getErrorStatus();
				$return .= " {$status}";
			}
		}

		return $return;
	}

	function getSubTitle() {
		$fullCode = $this->fullCode;

		$return = "{$fullCode}";
		if($this->pinyin != '*') {
			$pinyin = strtoupper($this->pinyin);
			$return .= "（{$pinyin}）";
		}

		if($this->qt) {
			if(!$this->qt->getErrorStatus()) {
				$lastClose = $this->qt->getLastClosePrice();
				$todayOpen = $this->qt->getTodayOpenPrice();
				$hPrice = $this->qt->getHighPrice();
				$lPrice = $this->qt->getLowPrice();

				$return .= " 高:{$hPrice}  低:{$lPrice}  收:{$lastClose}  开:{$todayOpen}";
			}
		}

		return $return;
	}

	function getLink() {
		return "http://gu.qq.com/".$this->fullCode;
	}
}

function getCurlData($url, $port=80,$timeout=10)
{
	$ch = curl_init();
	 // set port
	curl_setopt($ch, CURLOPT_PORT, $port);
	// drop http header
	curl_setopt($ch, CURLOPT_HEADER, FALSE);
	// get data as string
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	// set timeout
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);       
	curl_setopt($ch, CURLOPT_URL, $url);
    if(defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')){
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
	}

	// execute fetch
	$data = curl_exec($ch);
	$errno = curl_errno($ch);
	if($errno > 0) {
		//try one time
		$data = curl_exec($ch);
		$errno = curl_errno($ch);
		if($errno > 0){
			return false;
		}
	}
	if(empty($data)) {
	    return array();
	}
	return $data;
}

class StockQt 
{
    protected $items = array();

    function __construct($qtData = false) {
    	if($qtData) {
    		$this->items = $qtData;
    	}
    }
    //查询行情数据
    public function getQt($stock_code){
        $url = 'http://qt.gtimg.cn/q='.$stock_code;
        $data = getCurlData($url, 80, 2);
        $data = trim($data);
    	$edatas = explode(';', $data);

    	$codes = array();
    	foreach($edatas as $value){
            $it = explode('~',$value);
            if(trim($it[0])){
            	preg_match('/_([^_]*?)\=/', $it[0], $result);
        		$this->items[$result[1]] = $it;
            }
        }
	}
	//股票名称
	public function getName($code=''){
		if($code){
			return $this->items[$code][1];
		}else{
			return $this->items[1];
		}
	}
	//获得涨跌幅
	public function getPercent($code='') {
		$ret;
		if($code){
			$ret = $this->items[$code][32];
		}else{
			$ret = $this->items[32];
		}

		if($ret > 0) {
			$ret = '+'.$ret;
		}

		return $ret.'%';

	}
	//得到当前市价
    public function getPrice($code=''){
        if($code){
			return $this->items[$code][3];
		}else{
			return $this->items[3];
		}
	}
    //得到昨收价
    public function getLastClosePrice($code=''){
        if($code){
			return $this->items[$code][4];
		}else{
			return $this->items[4];
		}
	}
    //得到今开盘
    public function getTodayOpenPrice($code=''){
        if($code){
			return $this->items[$code][5];
		}else{
			return $this->items[5];
		}
	}
	//最低
    public function getHighPrice($code=''){
        if($code){
			return $this->items[$code][47];
		}else{
			return $this->items[47];
		}
	}
	//最高
    public function getLowPrice($code=''){
        if($code){
			return $this->items[$code][48];
		}else{
			return $this->items[48];
		}
	}
	//获得数据
	public function getItem($code) {
		return $this->items[$code];
	}

	public function getStatus($code='') {
		if($code){
			return $this->items[$code][40];
		}else{
			return $this->items[40];
		}
	}

	public function getErrorStatus() {
		$status = $this->getStatus();
		switch($status) {
			case 'D':
				return '退市';
			case 'S':
				return '停牌';
			case 'U':
				return '未上市';
			case 'Z':
				return '暂停上市';
			break;
		}

		return false;
	}
}
?>