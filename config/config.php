<?php
require "function/function_common.php";
require "function/function_table.php";

date_default_timezone_set('Asia/Shanghai');//定义时区，windows系统中php不能识别到系统时区

model(IN_UICE);//默认加在当前控制器对应model，比如当前控制器如果是case，则case_fetch(),case_update()等相关读写函数会被自动加载
model('company');//这个model比较特殊，是各类型企业的函数库，也包含企业信息的通用函数比如conpany_fetchinfo()

session_set_cookie_params(86400); 

session_start();

$db['host']="localhost";
$db['username']="root";
$db['password']="1";
$db['name']='starsys';

define('DB_LINK',mysql_connect($db['host'],$db['username'],$db['password']));

mysql_select_db($db['name'],DB_LINK);

db_query("SET NAMES 'UTF8'");

//初始化数据库，本系统为了代码书写简便，没有将数据库操作作为类封装，但有大量实用函数在function/function_common.php->db_()

$_G['action']='';
$_G['timestamp']=time();
$_G['microtime']=microtime(true);
$_G['date']=date('Y-m-d',$_G['timestamp']);
$_G['quarter']=date('y',$_G['timestamp']).ceil(date('m',$_G['timestamp'])/3);
$_G['require_export']=true;//页面头尾输出开关（含menu）
$_G['require_menu']=true;//顶部蓝条/菜单输出开关
$_G['as_popup_window']=false;
$_G['as_controller_default_page']=false;
$_G['actual_table']='';//借用数据表的controller的实际主读写表，如contact为client,query为case
$_G['document_root']="D:/files";//文件系统根目录物理位置
$_G['case_document_path']="D:/case_document";//案下文件物理位置
$_G['db_execute_time']=0;
$_G['db_executions']=0;
$_G['debug_mode']=true;
//定义一些系统配置，$_G不是php内置的大变量，是自定义的，为了在函数中可以方便地通过global $_G来获得所有配置

if($company_info=company_fetchInfo()){
	$_G+=$company_info;
}
//获得公司信息，见数据库，company表

//ucenter配置
if($_G['ucenter']){
	require 'config/config_ucenter.php';
	require 'plugin/client/client.php';
}
?>