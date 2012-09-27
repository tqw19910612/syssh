<?php
model('staff');
	
getPostData(function(){
	global $_G;
	post('client/name',$_SESSION['username'].'的新客户 '.date('Y-m-d h:i:s',$_G['timestamp']));
	post('client/abbreviation',$_SESSION['username'].'的新客户 '.date('Y-m-d h:i:s',$_G['timestamp']));
	
	post('client_extra/source_lawyer_name',$_SESSION['username']);
});

$q_source="SELECT * FROM client_source WHERE id='".post('client/source')."'";
$r_source=db_query($q_source);
post('source',db_fetch_array($r_source));
//取得当前客户的"来源"数据

if(post('client/source_lawyer')){
	post('client_extra/source_lawyer_name',db_fetch_field("SELECT name FROM staff WHERE id ='".post('client/source_lawyer')."'"));
}

if(is_posted('character')){
	post('client/character',$_POST['character']);
}

$submitable=false;//可提交性，false则显示form，true则可以跳转

if(is_posted('submit')){
	$submitable=true;

	$_SESSION[IN_UICE]['post']=array_replace_recursive($_SESSION[IN_UICE]['post'],$_POST);

	if(is_posted('submit/client_client')){
		post('client_client_extra/show_add_form',true);
		
		$client_check=client_check(post('client_client_extra/name'),'array');

		if($client_check>0){
			post('client_client/client_right',$client_check['id']);
			showMessage('系统中已经存在 '.$client_check['name'].'，已自动识别并添加');

		}elseif($client_check==-1){//如果client_client添加的客户不存在，则先添加客户
			$new_client=array(
				'name'=>post('client_client_extra/name'),
				'abbreviation'=>post('client_client_extra/name'),
				'character'=>post('client_client_extra/character')=='单位'?'单位':'自然人',
				'classification'=>'客户',
				'type'=>'潜在客户',
			);
			post('client_client/client_right',client_add($new_client));
			
			client_addContact_phone_email(post('client_client/client_right'),post('client_client_extra/phone'),post('client_client_extra/email'));

			showMessage(
				'<a href="javascript:showWindow(\'client?edit='.$new_client['id'].'\')" target="_blank">新客户 '.
				$new_client['name'].
				' 已经添加，点击编辑详细信息</a>',
			'notice');

		}else{
			//除了不存在意外的其他错误，如关键字多个匹配
			$submitable=false;
		}

		post('client_client/client_left',post('client/id'));
		
		if($submitable && client_addRelated(post('client_client'))){
			unset($_SESSION['client']['post']['client_client']);
			unset($_SESSION['client']['post']['client_client_extra']);
		}
	}
	
	if(is_posted('submit/client_contact')){
		post('client_contact/client',post('client/id'));
		
		if(client_addContact(post('client_contact'))){
			unset($_SESSION['client']['post']['client_contact']);
		}
	}
	
	if(is_posted('submit/client_client_set_default')){
		if(count(post('client_client_check'))>1){
			showMessage('你可能试图设置多个默认联系人，这是不被允许的','warning');

		}elseif(count(post('client_client_check')==1)){
			$client_client_set_default_keys=array_keys(post('client_client_check'));
			client_setDefaultRelated($client_client_set_default_keys[0],post('client/id'));

			showMessage('成功设置默认联系人');

		}elseif(count(post('client_client_check')==0)){
			client_clearDefaultRelated(post('client/id'));
		}
	}

	if(is_posted('submit/client_client_delete')){
		client_deleteRelated(post('client_client_check'));
	}

	if(is_posted('submit/client_contact_delete')){
		client_deleteContact(post('client_contact_check'));
	}
	
	if(post('client/character')=='自然人'){
		//自然人简称就是名称
		post('client/abbreviation',post('client/name'));
		if(!post('client/birthday')){
			unset($_SESSION['client']['post']['client']['birthday']);
		}

	}elseif(array_dir('_POST/client/abbreviation')==''){
		//单位简称必填
		$submitable=false;
		showMessage('请填写客户简称','warning');
	}
	
	if(!post('client/source',client_setSource(post('source/type'),post('source/detail')))){
		$submitable=false;
	}
	
	if(post('client/source_lawyer',staff_check(post('client_extra/source_lawyer_name'),'id',true,'client/source_lawyer'))<0){
		$submitable=false;
	}
	processSubmit($submitable);
}

//准备client_add表单中的小表
$q_client_client="
	SELECT 
		client_client.id AS id,client_client.role,client_client.client_right,client_client.is_default_contact,
		client.abbreviation AS client_right_name,client.classification,
		phone.content AS client_right_phone,email.content AS client_right_email
	FROM 
		client_client INNER JOIN client ON client_client.client_right=client.id
		LEFT JOIN (
			SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type IN('手机','固定电话') GROUP BY client
		)phone ON client.id=phone.client
		LEFT JOIN (
			SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type='电子邮件' GROUP BY client
		)email ON client.id=email.client
	WHERE `client_left`='".post('client/id')."'
	ORDER BY role";

$field_client=array(
	'checkbox'=>array('title'=>'<input type="submit" name="submit[client_client_delete]" value="删" />','orderby'=>false,'content'=>'<input type="checkbox" name="client_client_check[{id}]" >','td_title'=>' width=60px'),
	'client_right_name'=>array('title'=>'名称<input type="submit" name="submit[client_client_set_default]" value="默认" />','eval'=>true,'content'=>"
		\$return='';
		\$return.='<a href=\"javascript:showWindow(\''.('{classification}'=='客户'?'client':'contact').'?edit={client_right}\')\">{client_right_name}</a>';
		if('{is_default_contact}'){
			\$return.='*';
		}
		return \$return;
	",'orderby'=>false),
	'client_right_phone'=>array('title'=>'电话','orderby'=>false),
	'client_right_email'=>array('title'=>'电邮','surround'=>array('mark'=>'a','href'=>'mailto:{client_right_email}')),
	'role'=>array('title'=>'关系','orderby'=>false)
);

$q_client_contact="
	SELECT 
		client_contact.id,client_contact.comment,client_contact.content,client_contact.type
	FROM client_contact INNER JOIN client ON client_contact.client=client.id
	WHERE client_contact.client='".post('client/id')."'
";

$field_client_contact=array(
	'checkbox'=>array('title'=>'<input type="submit" name="submit[client_contact_delete]" value="删" />','orderby'=>false,'content'=>'<input type="checkbox" name="client_contact_check[{id}]" >','td_title'=>' width=60px'),
	'type'=>array('title'=>'类别','orderby'=>false),
	'content'=>array('title'=>'内容','eval'=>true,'content'=>"
		if('{type}'=='电子邮件'){
			return '<a href=\"mailto:{content}\" target=\"_blank\">{content}</a>';
		}else{
			return '{content}';
		}
	",'orderby'=>false),
	'comment'=>array('title'=>'备注','orderby'=>false)
);

$q_client_case="
SELECT case.id,case.name AS case_name,case.num,	
	GROUP_CONCAT(DISTINCT staff.name) AS lawyers
FROM `case`
	LEFT JOIN case_lawyer ON (case.id=case_lawyer.case AND case_lawyer.role='主办律师')
	LEFT JOIN staff ON staff.id=case_lawyer.lawyer
WHERE case.id IN (
	SELECT `case` FROM case_client WHERE client='".post('client/id')."'
)
GROUP BY case.id
HAVING id IS NOT NULL
";

$field_client_case=array(
	'num'=>array('title'=>'案号','surround'=>array('mark'=>'a','href'=>'javascript:window.rootOpener.location.href=\'case?edit={id}\';window.opener.parent.focus();'),'orderby'=>false),
	'case_name'=>array('title'=>'案名','orderby'=>false),
	'lawyers'=>array('title'=>'主办律师','orderby'=>false)
);

if(post('client/character')=='单位'){
	require 'view/client_add_artificial.htm';

}else{
	require 'view/client_add_natural.htm';
}
?>