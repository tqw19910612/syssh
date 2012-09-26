<?php
function case_fetch($id){
	//finance和manager可以看到所有案件，其他律师只能看到自己涉及的案件
	$query="
	SELECT * 
	FROM `case` 
	WHERE id='".post('case/id')."' 
		AND ( '".(is_logged('manager') || is_logged('finance') || is_logged('admin'))."'=1 OR uid='".$_SESSION['id']."' OR id IN (
			SELECT `case` FROM case_lawyer WHERE lawyer='".$_SESSION['id']."'
		))
	";
	return db_fetch_first($query,true);
}

function case_add($data){
	$field=db_list_fields('case');
    $data=array_keyfilter($data,$field);
    $data['display']=1;
    $data+=uidTime();

    return db_insert('case',$data);
}

function case_update($case_id,$data){
	$field=db_list_fields('case');
    $data=array_keyfilter($data,$field);
	$data+=uidTime();
    
	return db_update('case',$data,"id='".$case_id."'");
}

function case_addDocument($case,$data){
	$field=array('name','type','doctype','size','comment');
	$data=array_keyfilter($data,$field);
	$data['case']=$case;
	$data+=uidTime();
	
	return db_insert('case_document',$data);
}

function case_addFee($case,$data){
    $field=array('fee','type','receiver','condition','pay_time','comment');
	$data=array_keyfilter($data,$field);
	$data['case']=$case;
	$data+=uidTime();
	return db_insert('case_fee',$data);
}

function case_addFeeTiming($case,$data){
	//TODO case_addFeeTiming
}

function case_addLawyer($case,$data){
	if(!isset($data['lawyer'])){
		return false;
	}
	
	$field=array('lawyer','role','hourly_fee','contribute');
	foreach($data as $key => $value){
		if(!in_array($key,$field)){
			unset($data[$key]);
		}
	}
	
	$data['case']=$case;
	
	$data+=uidTime();
	
	return db_insert('case_lawyer',$data);
}
function case_getStatus($is_reviewed,$locked,$apply_file,$is_query,$finance_review,$info_review,$manager_review,$filed,$contribute_sum,$uncollected){
	$status_expression='';

	$file_review=array(
		'finance'=>$finance_review,
		'info'=>$info_review,
		'manager'=>$manager_review,
		'filed'=>$filed
	);
	
	$file_review_name=array(
		'finance'=>'财务审核',
		'info'=>'信息审核',
		'manager'=>'主管审核',
		'filed'=>'实体归档'
	);
	
	$status_color=array(
		-1=>'800',//红：异常
		0=>'000',//黑：正常
		1=>'080',//绿：完成
		2=>'F80',//黄：警告，提示
		3=>'08F',//蓝：超目标完成
		4=>'888'//灰：忽略
	);
	
	if($is_query){
		return '咨询';

	}elseif($apply_file){
		$review_status=0;//归档审核状态
		if($finance_review==1 && $info_review==1 && $manager_review==1){
			$review_status=1;
		}elseif($finance_review==-1 || $info_review==-1 || $manager_review==-1){
			$review_status=-1;
		}elseif($finance_review==1 || $info_review==1 || $manager_review==1){
			$review_status=2;
		}
		
		$review_status_string='';
		
		foreach($file_review as $name => $value){
			switch($value){
				case 1:$review_status_string.=$file_review_name[$name].'：通过';break;
				case -1:$review_status_string.=$file_review_name[$name].'：驳回';break;
				case 0:$review_status_string.=$file_review_name[$name].'：等待';
			}
			$review_status_string.="\n";
		}
		
		$status_expression.='<span title="'.$review_status_string.'" style="color:#'.$status_color[$review_status].'">归</span>';

	}else{
		$review_status_string='';
		switch($is_reviewed){
			case 1:$review_status_string.='立案审核：通过';break;
			case -1:$review_status_string.='立案审核：驳回';break;
			case 0:$review_status_string.='立案审核：等待';
		}
		$status_expression.='<span title="'.$review_status_string.'" style="color:#'.$status_color[$is_reviewed].'">立</span>';
	}
	
	if($locked){
		$status_expression.='<span title="已锁定" style="color:#080">锁</span>';
	}else{
		$status_expression.='<span title="部分未锁定" style="color:#800">锁</span>';
	}
	
	if($contribute_sum<0.7){
		$status_expression.='<span title="贡献已分配'.($contribute_sum*100).'%" style="color:#800">配</span>';
	}elseif($contribute_sum<1){
		$status_expression.='<span title="贡献已分配'.($contribute_sum*100).'%" style="color:#F80">配</span>';
	}else{
		$status_expression.='<span title="贡献已分配'.($contribute_sum*100).'%" style="color:#080">配</span>';
	}
	
	if($uncollected>0){
		$status_expression.='<span title="未收款：'.$uncollected.'"元 style="color:#800">款</span>';
	}elseif($uncollected<0){
		$status_expression.='<span title="费用已到账（超预估收款：'.-$uncollected.'元）" style="color:#08F">款</span>';
	}elseif($uncollected==='0.00'){
		$status_expression.='<span title="费用已到账" style="color:#080">款</span>';
	}else{
		$status_expression.='<span title="未预估收费" style="color:#888">款</span>';
	}
		
	return $status_expression;
}

function case_getStatusById($case_id){
	$case_data=db_fetch_first("SELECT is_reviewed,type_lock,client_lock,lawyer_lock,fee_lock,is_query,apply_file,finance_review,info_review,manager_review,filed FROM `case` WHERE id = '".$case_id."'");
	extract($case_data);
	if($type_lock && $client_lock && $lawyer_lock && $fee_lock){
		$locked=true;
	}else{
		$locked=false;
	}
	
	$uncollected=db_fetch_field("
		SELECT IF(amount_sum IS NULL,fee_sum,fee_sum-amount_sum) AS uncollected FROM
		(
			SELECT `case`,SUM(fee) AS fee_sum FROM case_fee WHERE type<>'办案费' AND reviewed=0 AND `case`='".post('case/id')."'
		)case_fee_grouped
		LEFT JOIN
		(
			SELECT `case`, SUM(amount) AS amount_sum FROM account WHERE reviewed=0 AND `case`='".$case_id."'
		)account_grouped
		USING (`case`)
	");
	
	$contribute_sum=db_fetch_field("
		SELECT SUM(contribute) AS contribute_sum
		FROM case_lawyer
		WHERE `case`='".$case_id."'
	");
	
	return case_getStatus($is_reviewed,$locked,$apply_file,$is_query,$finance_review,$info_review,$manager_review,$filed,$contribute_sum,$uncollected);
}

function case_reviewMessage($reviewWord,$lawyers){
	$message='案件[url=http://sys.lawyerstars.com/case?edit='.post('case/id').']'.strip_tags(post('case/name')).'[/url]'.$reviewWord.'，"'.post('review_message').'"';
	foreach($lawyers as $lawyer){
		sendMessage($lawyer,$message);
	}
}

function case_getIdByCaseFee($case_fee){
	return db_fetch_field("SELECT `case` FROM case_fee WHERE id='".intval($case_fee)."'",'case');
}

/*
	日志添加界面，根据日志类型获得案件列表
	$schedule_type:0:案件,1:所务,2:营销
*/
function case_getListByScheduleType($schedule_type){
	
	$option_array=array();
	
	$q_option_array="SELECT id,name FROM `case` WHERE display=1";
	
	if($schedule_type==0){
		$q_option_array.=" AND ((id>=20 AND filed IN ('在办','咨询') AND (id IN (SELECT `case` FROM case_lawyer WHERE lawyer='".$_SESSION['id']."') OR uid = '".$_SESSION['id']."')) OR id=10)";
	
	}elseif($schedule_type==1){
		$q_option_array.=" AND id<10 AND id>0";
	
	}elseif($schedule_type==2){
		$q_option_array.=" AND id<=20 AND id>10";

	}
	
	$q_option_array.=" ORDER BY time_contract DESC";
	
	$option_array=db_toArray($q_option_array);
	$option_array=array_sub($option_array,'name','id');
	
	foreach($option_array as $case_id => $case_name){
		$option_array[$case_id]=strip_tags($case_name);
	}

	return $option_array;	
}

//根据客户id获得其参与案件的收费
function case_getFeeListByClient($client_id){
	$option_array=array();
	
	$q_option_array="
		SELECT case_fee.id,case_fee.type,case_fee.fee,case_fee.pay_time,case_fee.receiver,case.name
		FROM case_fee INNER JOIN `case` ON case_fee.case=case.id
		WHERE case.id IN (SELECT `case` FROM case_client WHERE client='".$client_id."')";
	
	$r_option_array=db_query($q_option_array);
	
	while($a_option_array=mysql_fetch_array($r_option_array)){
		$option_array[$a_option_array['id']]=strip_tags($a_option_array['name']).'案 '.$a_option_array['type'].' ￥'.$a_option_array['fee'].' '.date('Y-m-d',$a_option_array['pay_time']).($a_option_array['type']=='办案费'?' '.$a_option_array['receiver'].'收':'');
	}

	return $option_array;	
}

//根据案件ID获得收费array
function case_getFeeOptions($case_id){
	$option_array=array();
	
	$q_option_array="
		SELECT case_fee.id,case_fee.type,case_fee.fee,case_fee.pay_time,case_fee.receiver,case.name
		FROM case_fee INNER JOIN `case` ON case_fee.case=case.id
		WHERE case.id='".$case_id."'";
	
	$r_option_array=db_query($q_option_array);
	
	while($a_option_array=db_fetch_array($r_option_array)){
		$option_array[$a_option_array['id']]=strip_tags($a_option_array['name']).'案 '.$a_option_array['type'].' ￥'.$a_option_array['fee'].' '.date('Y-m-d',$a_option_array['pay_time']).($a_option_array['type']=='办案费'?' '.$a_option_array['receiver'].'收':'');
	}

	return $option_array;	
}

function case_feeConditionPrepend($case_fee_id,$new_condition){
	global $_G;
	
	db_update('case_fee',array('condition'=>"_CONCAT('".$new_condition."\\n',`condition`)_",'uid'=>$_SESSION['id'],'username'=>$_SESSION['username'],'time'=>$_G['timestamp']),"id='".$case_fee_id."'");
	
	return db_fetch_field("SELECT `condition` FROM case_fee WHERE id = '".$case_fee_id."'");
}

function case_addClient($case_id,$client_id,$role){
	return db_insert('case_client',array('case'=>$case_id,'client'=>$client_id,'role'=>$role));
}

//增减案下律师的时候自动计算贡献
function case_calcContribute($case_id){
	$case_lawyer_array=db_toArray("SELECT id,lawyer,role FROM case_lawyer WHERE `case`='".$case_id."'");
	
	$case_lawyer_array=array_sub($case_lawyer_array,'role','id');

	//各角色计数器
	$role_count=array('接洽律师'=>0,'接洽律师（次要）'=>0,'主办律师'=>0,'协办律师'=>0,'律师助理'=>0);

	foreach($case_lawyer_array as $id => $role){
		if(!isset($role_count[$role])){
			$role_count[$role]=0;
		}
		$role_count[$role]++;
	}
	
	$contribute=array('接洽'=>0.15,'办案'=>0.35);
	if(isset($role_count['信息提供（10%）']) && $role_count['信息提供（10%）']==1 && !isset($role_count['信息提供（20%）'])){
		$contribute['接洽']=0.25;
	}
	
	foreach($case_lawyer_array as $id=>$role){
		if($role=='接洽律师（次要）' && isset($role_count['接洽律师']) && $role_count['接洽律师']==1){
			db_update('case_lawyer',array('contribute'=>$contribute['接洽']*0.3),"id='".$id."'");

		}elseif($role=='接洽律师'){
			if(isset($role_count['接洽律师（次要）']) && $role_count['接洽律师（次要）']==1){
				db_update('case_lawyer',array('contribute'=>$contribute['接洽']*0.7),"id='".$id."'");
			}else{
				db_update('case_lawyer',array('contribute'=>$contribute['接洽']/$role_count[$role]),"id='".$id."'");
			}

		}elseif($role=='主办律师'){
			if(isset($role_count['协办律师']) && $role_count['协办律师']){
				db_update('case_lawyer',array('contribute'=>($contribute['办案']-0.05)/$role_count[$role]),"id='".$id."'");
			}else{
				db_update('case_lawyer',array('contribute'=>$contribute['办案']/$role_count[$role]),"id='".$id."'");
			}

		}elseif($role=='协办律师'){
			db_update('case_lawyer',array('contribute'=>0.05/$role_count[$role]),"id='".$id."'");
		}
	}
}

function case_lawyerRoleCheck($case_id,$new_role,$actual_contribute=NULL){
	if(strpos($new_role,'信息提供')!==false && db_fetch_field("SELECT SUM(contribute) FROM case_lawyer WHERE role LIKE '信息提供%' AND `case`='".$case_id."'")+substr($new_role,15,2)/100>0.2){
		//信息贡献已达到20%
		showMessage('信息提供贡献已满额','warning');
		return false;
		
	}elseif(strpos($new_role,'接洽律师')!==false && db_fetch_field("SELECT COUNT(id) FROM case_lawyer WHERE role LIKE '接洽律师%' AND `case`='".$case_id."'")>=2){
		//接洽律师已达到2名
		showMessage('接洽律师不能超过2位','warning');
		return false;
	}
	
	if($new_role=='信息提供（20%）'){
		return 0.2;

	}elseif($new_role=='信息提供（10%）'){
		return 0.1;

	}elseif($new_role=='实际贡献'){
		$actual_contribute=$actual_contribute/100;
		
		if(!$actual_contribute){
			$actual_contribute_left=
				0.3-db_fetch_field("SELECT SUM(contribute) FROM case_lawyer WHERE `case`='".$case_id."' AND role='实际贡献'");
			if($actual_contribute_left>0){
				return $actual_contribute_left;
			}else{
				showMessage('实际贡献额已分配完','warning');
				return false;
			}
			
		}elseif(db_fetch_field("SELECT SUM(contribute) FROM case_lawyer WHERE `case`='".$case_id."' AND role='实际贡献'")+($actual_contribute/100)>0.3){
			showMessage('实际贡献总数不能超过30%','warning');
			return false;

		}else{
			return $actual_contribute;
		}
	}else{
		return 0;
	}
}

function case_getRoles($case_id){
	if($case_role=db_toArray("SELECT lawyer,role FROM case_lawyer WHERE `case`='".$case_id."'")){
		return $case_role;
	}else{
		return false;
	}
}

function case_getPartner($case_role){
	if(empty($case_role)){
		return false;
	}
	foreach($case_role as $lawyer_role){
		if($lawyer_role['role']=='督办合伙人'){
			return $lawyer_role['lawyer'];
		}
	}
	return false;
}

function case_getlawyers($case_role){
	if(empty($case_role)){
		return false;
	}
	$lawyers=array();
	foreach($case_role as $lawyer_role){
		if(!in_array($lawyer_role['lawyer'],$lawyers) && $lawyer_role['role']!='督办合伙人'){
			$lawyers[]=$lawyer_role['lawyer'];
		}
	}
	return $lawyers;
}

function case_getMyRoles($case_role){
	if(empty($case_role)){
		return false;
	}
	$my_role=array();
	foreach($case_role as $lawyer_role){
		if($lawyer_role['lawyer']==$_SESSION['id']){
			$my_role[]=$lawyer_role['role'];
		}
	}
	return $my_role;
}

function case_getClientList($case_id,$client_lock){
//案件相关人信息
	$query="
		SELECT case_client.id,case_client.client,case_client.role,
			client.abbreviation AS client_name,client.classification,
			default_contact.contact,default_contact.name AS contact_name,default_contact.classification AS contact_classification,
			if(LENGTH(default_contact.phone),default_contact.phone,phone.content) AS phone,
			if(LENGTH(default_contact.email),default_contact.email,email.content) AS email
		FROM 
			case_client INNER JOIN client ON (case_client.client=client.id)
	
			LEFT JOIN (
				SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type IN('手机','固定电话') GROUP BY client
			)phone ON client.id=phone.client
	
			LEFT JOIN (
				SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type='电子邮件' GROUP BY client
			)email ON client.id=email.client
	
			LEFT JOIN(
				SELECT client_client.client_left AS client,client_client.client_right AS contact,client.abbreviation AS name,client.classification,phone.content AS phone,email.content AS email
				FROM client_client
					INNER JOIN client ON client_client.client_right=client.id AND client_client.is_default_contact=1
					LEFT JOIN (
							SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type IN('手机','固定电话') GROUP BY client
					)phone ON client.id=phone.client
					LEFT JOIN (
							SELECT client,GROUP_CONCAT(content) AS content FROM client_contact WHERE type='电子邮件' GROUP BY client
					)email ON client.id=email.client
			)default_contact
			ON client.id=default_contact.client
	
		WHERE case_client.`case`='".$case_id."'
		ORDER BY client.classification
	";
	
	$field=array(
		'client_name'=>array('title'=>'名称','eval'=>true,'content'=>"
			\$return='';
			if(!post('case/client_lock')){
				\$return.='<input type=\"checkbox\" name=\"case_client_check[{id}]\" />';
			}
			\$return.='<a href=\"javascript:showWindow(\''.('{classification}'=='客户'?'client':'contact').'?edit={client}\')\">{client_name}</a>';
			return \$return;
		",'orderby'=>false),
		'contact_name'=>array('title'=>'联系人','eval'=>true,'content'=>"
			return '<a href=\"javascript:showWindow(\''.('{contact_classification}'=='客户'?'client':'contact').'?edit={contact}\')\">{contact_name}</a>';
		",'orderby'=>false),
		'phone'=>array('title'=>'电话','td'=>'class="ellipsis" title="{phone}"'),
		'email'=>array('title'=>'电邮','surround'=>array('mark'=>'a','href'=>'mailto:{email}','title'=>'{email}','target'=>'_blank'),'td'=>'class="ellipsis"'),
		'role'=>array('title'=>'本案地位','orderby'=>false),
		'classification'=>array('title'=>'类型','td_title'=>'width="60px"','orderby'=>false)
	);
	
	if(!$client_lock){
		//客户锁定时不显示删除按钮
		$field['client_name']['title']='<input type="submit" name="submit[case_client_delete]" value="删" />'.$field['client_name']['title'];
	}
	
	return fetchTableArray($query,$field);
}

function case_getStaffList($case_id,$staff_lock,$timing_fee){
//案件律师信息
	$query="
		SELECT
			case_lawyer.id,case_lawyer.role,case_lawyer.hourly_fee,CONCAT(TRUNCATE(case_lawyer.contribute*100,1),'%') AS contribute,
			staff.name AS lawyer_name,
			TRUNCATE(SUM(account.amount)*contribute,2) AS contribute_amount,
			lawyer_hour.hours_sum
		FROM 
			case_lawyer	INNER JOIN staff ON staff.id=case_lawyer.lawyer
			LEFT JOIN account ON case_lawyer.case=account.case AND account.name IN ('律师费','顾问费','咨询费')
			LEFT JOIN (
				SELECT uid,SUM(hours_checked) AS hours_sum FROM schedule WHERE schedule.`case`='".$case_id."' AND hours_checked IS NOT NULL GROUP BY uid
			)lawyer_hour
			ON lawyer_hour.uid=case_lawyer.lawyer
		WHERE case_lawyer.case='".$case_id."'
			
		GROUP BY case_lawyer.id
		ORDER BY case_lawyer.role";
	
	$field=array(
		'lawyer_name'=>array('title'=>'名称','content'=>'{lawyer_name}','orderby'=>false),
		'role'=>array('title'=>'本案职位','orderby'=>false)
	);
	if($timing_fee){
		$field['hourly_fee']=array('title'=>'计时收费小时费率','td'=>'class="editable" id="{id}"','orderby'=>false);
	}
	
	$field['contribute']=array('title'=>'贡献','eval'=>true,'content'=>"
		\$hours_sum_string='';
		if('{hours_sum}'){
			\$hours_sum_string='<span class=\"right\">{hours_sum}小时</span>';
		}
		
		return \$hours_sum_string.'<span>{contribute}'.('{contribute_amount}'?' ({contribute_amount})':'').'</span>';
	",'orderby'=>false);
	
	if(!$staff_lock || is_logged('manager')){
		//律师锁定时不显示删除按钮
		$field['lawyer_name']['title']='<input type="submit" name="submit[case_lawyer_delete]" value="删" />'.$field['lawyer_name']['title'];
		$field['lawyer_name']['content']='<input type="checkbox" name="case_lawyer_check[{id}]">'.$field['lawyer_name']['content'];
	}
	
	return fetchTableArray($query,$field);
}

function case_getFeeList($case_id,$fee_lock){
//案件律师费约定信息
	$query="
		SELECT case_fee.id,case_fee.type,case_fee.condition,case_fee.pay_time,case_fee.fee,case_fee.reviewed,
			if(SUM(account.amount) IS NULL,'',SUM(account.amount)) AS fee_received,
			FROM_UNIXTIME(MAX(account.time_occur),'%Y-%m-%d') AS fee_received_time
		FROM 
			case_fee LEFT JOIN account ON case_fee.id=account.case_fee
		WHERE case_fee.case='".$case_id."' AND case_fee.type<>'办案费'
		GROUP BY case_fee.id";
	
	$field=array(
		'type'=>array('title'=>'类型','td'=>'id="{id}"','content'=>'{type}','orderby'=>false),
		'fee'=>array('title'=>'数额','eval'=>true,'content'=>"
			\$return='{fee}'.('{fee_received}'==''?'':' <span title=\"{fee_received_time}\">（到账：{fee_received}）</span>');
			if('{reviewed}'){
				\$return=surround(\$return,array('mark'=>'span','style'=>'color:#080'));
			}
			return \$return;
		",'orderby'=>false),
		'condition'=>array('title'=>'条件','td'=>'class="ellipsis" title="{condition}"','orderby'=>false),
		'pay_time'=>array('title'=>'预计时间','eval'=>true,'content'=>"
			return date('Y-m-d',{pay_time});
		",'orderby'=>false
		)
	);
	
	if(!$fee_lock || is_logged('finance')){
		$field['type']['title']='<input type="submit" name="submit[case_fee_delete]" value="删" />'.$field['type']['title'];
		$field['type']['content']='<input type="checkbox" name="case_fee_check[{id}]" >'.$field['type']['content'];
	}
	
	return fetchTableArray($query,$field);
}

function case_getTimingFeeString($case_id){
	$query="SELECT CONCAT('包含',included_hours,'小时，','账单日：',bill_day,'，付款日：',payment_day,'，付款周期：',payment_cycle,'个月，合同周期：',contract_cycle,'个月，','合同起始日：',FROM_UNIXTIME(time_start,'%Y-%m-%d')) AS case_fee_timing_string FROM case_fee_timing WHERE `case`='".$case_id."'";
	return db_fetch_field($query);
}

function case_getFeeMiscList($case_list,$fee_lock){
	$query="
		SELECT case_fee.id,case_fee.type,case_fee.receiver,case_fee.comment,case_fee.pay_time,case_fee.fee,
			if(SUM(account.amount) IS NULL,'',SUM(account.amount)) AS fee_received
		FROM 
			case_fee LEFT JOIN account ON case_fee.id=account.case_fee
		WHERE case_fee.case='".post('case/id')."' AND case_fee.type='办案费'
		GROUP BY case_fee.id";
	
	$field=array(
		'receiver'=>array('title'=>'收款方','content'=>'<input type="checkbox" name="case_fee_check[{id}]" />{receiver}','orderby'=>false),
		'fee'=>array('title'=>'数额','eval'=>true,'content'=>"
			return '{fee}'.('{fee_received}'==''?'':' （到账：{fee_received}）');
		",'orderby'=>false),
		'comment'=>array('title'=>'备注','orderby'=>false),
		'pay_time'=>array('title'=>'预计时间','eval'=>true,'content'=>"
			return date('Y-m-d',{pay_time});
		",'orderby'=>false
		)
	);
	
	if(!$fee_lock){
		$field['receiver']['title']='<input type="submit" name="submit[case_fee_delete]" value="删" />'.$field['receiver']['title'];
	}
	
	return fetchTableArray($query,$field);
}

function case_getDocumentList($case_id){
	$query="SELECT *
		FROM 
			case_document
		WHERE display=1 AND `case`='".$case_id."'
		ORDER BY time DESC";
	$field=array(
		'type'=>array(
			'title'=>'',
			'eval'=>true,
			'content'=>"
				if('{type}'==''){
					\$image='folder';
				}elseif(is_file('web/images/file_type/{type}.png')){
					\$image='{type}';
				}else{
					\$image='unknown';
				}
				return '<img src=\"images/file_type/'.\$image.'.png\" alt=\"{type}\" />';
			",
			'td_title'=>'width="70px"',
			'orderby'=>false
		),
		'name'=>array('title'=>'文件名','td_title'=>'width="150px"','surround'=>array('mark'=>'a','href'=>'case?document={id}'),'orderby'=>false),
		'doctype'=>array('title'=>'类型','td_title'=>'width="80px"'),
		'comment'=>array('title'=>'备注','orderby'=>false),
		'time'=>array('title'=>'时间','td_title'=>'width="60px"','eval'=>true,'content'=>"
			return date('m-d H:i',{time});
		"),
		'username'=>array('title'=>'上传人','td_title'=>'width="90px"')
	);
	return fetchTableArray($query,$field);
}

function case_getScheduleList($case_id){
	$query="SELECT *
		FROM 
			schedule
		WHERE display=1 AND completed=1 AND `case`='".$case_id."'
		ORDER BY time_start DESC
		LIMIT 10";
	
	$field=array(
		'name'=>array('title'=>'标题','td_title'=>'width="150px"','surround'=>array('mark'=>'a','href'=>'javascript:showWindow(\'schedule?edit={id}\')'),'orderby'=>false),
		'time_start'=>array('title'=>'时间','td_title'=>'width="60px"','eval'=>true,'content'=>"
			return date('m-d H:i',{time_start});
		",'orderby'=>false),
		'username'=>array('title'=>'填写人','td_title'=>'width="90px"','orderby'=>false)
	);
	return fetchTableArray($query,$field);
}

function case_getPlanList($case_id){
	$query="SELECT *
		FROM 
			schedule
		WHERE display=1 AND completed=0 AND `case`='".$case_id."'
		ORDER BY time_start
		LIMIT 10";
	
	$field=array(
		'name'=>array('title'=>'标题','td_title'=>'width="150px"','surround'=>array('mark'=>'a','href'=>'javascript:showWindow(\'schedule?edit={id}\')'),'orderby'=>false),
		'time_start'=>array('title'=>'时间','td_title'=>'width="60px"','eval'=>true,'content'=>"
			return date('m-d H:i',{time_start});
		",'orderby'=>false),
		'username'=>array('title'=>'填写人','td_title'=>'width="90px"','orderby'=>false)
	);
	return fetchTableArray($query,$field);
}

function case_getClientRole($case_id){
	//获得当前案件的客户-相对方名称
	$query="
		SELECT * FROM
		(
			SELECT case_client.client,client.abbreviation AS client_name,role AS client_role 
			FROM case_client INNER JOIN client ON case_client.client=client.id 
			WHERE client.classification='客户' AND `case`='".$case_id."'
			ORDER BY case_client.id
			LIMIT 1
		)client LEFT JOIN
		(
			SELECT client AS opposite,client.abbreviation AS opposite_name,role AS opposite_role 
			FROM case_client LEFT JOIN client ON case_client.client=client.id 
			WHERE client.classification='相对方' AND `case`='".$case_id."'
			LIMIT 1
		)opposite
		ON 1=1";	
	return db_fetch_first($query);
}

/*
 * 根据案件信息，获得案号
 * $case参数为array，需要包含filed,classification,type,type_lock,first_contact/time_contract键
 */
function case_getNum($case,$case_client_role=NULL){
	global $_G;
	$case_num=array();
	
	if(is_null($case_client_role)){
		$case_client_role=case_getClientRole($case['id']);
	}
	
	if($case['filed']!='咨询' && !$case_client_role['client']){
		showMessage('申请案号前应当至少添加一个客户','warning');
	}else{
		if($case['filed']=='咨询'){
			$case_num['classification_code']='询';
			$case_num['type_code']='';
		}else{
			switch($case['classification']){
				case '诉讼':$case_num['classification_code']='诉';break;
				case '非诉讼':$case_num['classification_code']='非';break;
				case '法律顾问':$case_num['classification_code']='顾';break;
				case '内部行政':$case_num['classification_code']='内';break;
				default:'';
			}
			switch($case['type']){
				case '房产':$case_num['type_code']='（房）';break;
				case '公司':$case_num['type_code']='（公）';break;
				case '婚姻':$case_num['type_code']='（婚）';break;
				case '劳动':$case_num['type_code']='（劳）';break;
				case '金融':$case_num['type_code']='（金）';break;
				case '继承':$case_num['type_code']='（继）';break;
				case '知产':$case_num['type_code']='（知）';break;
				case '合同':$case_num['type_code']='（合）';break;
				case '刑事':$case_num['type_code']='（刑）';break;
				case '行政':$case_num['type_code']='（行）';break;
				case '其他':$case_num['type_code']='（他）';break;
				case '公民个人':$case_num['type_code']='（个）';break;
				case '侵权':$case_num['type_code']='（侵）';break;
				case '移民':$case_num['type_code']='（移）';break;
				case '留学':$case_num['type_code']='（留）';break;
				case '企业':$case_num['type_code']='（企）';break;
				case '事业单位':$case_num['type_code']='（事）';break;
				case '个人事务':$case_num['type_code']='（个）';break;
				default:$case_num['type_code']='';
			}
		}
		$case_num['case']=$case['id'];
		$case_num+=uidTime();
		$case_num['year_code']=substr($case['filed']=='咨询'?$case['first_contact']:$case['time_contract'],0,4);
		db_insert('case_num',$case_num,true,true);
		$case_num['number']=db_fetch_field("SELECT number FROM case_num WHERE `case`='".$case['id']."'");
		if($case['filed']=='在办'){
			post('case/type_lock',1);//申请正式案号之后不可以再改变案件类别
		}
		post('case/display',1);//申请案号以后案件方可见
		$num='沪星'.$case_num['classification_code'].$case_num['type_code'].$case_num['year_code'].'第'.$case_num['number'].'号';
		db_update('case',array('num'=>$num),"id='".$case['id']."'");
		return $num;
	}
}
?>