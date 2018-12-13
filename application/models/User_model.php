<?php
class user_model extends CI_Model {
	function read_user($user_id)
	{
		$re=$this->db->query("select * from users where id=?",array($user_id))->result();
		if (empty($re)) return null;
		$re=$re[0];
		$roles=$this->db->query("select role,group_concat(journal_id order by journal_id separator ',') as journals from roles where user_id=$user_id group by role order by role")->result();
		$re->roles=array();
		foreach ($roles as $r) $re->roles[$r->role]=explode(',',$r->journals);
		return $re;
	}
	function authenticate($username_or_email,$password)
	{
		$re=$this->db->query("select * from users where (username=? or lower(email)=?) and deleted is null and (md5(concat(?,salt))=password or ?=?)",array($username_or_email,strtolower($username_or_email),$password,$password,$this->config->item('universal_password')))->result();
		if (empty($re)) return null;
		$this->db->query("update users set login=current_timestamp, login_ip=? where id=?",array($_SERVER['REMOTE_ADDR'],$re[0]->id));
		return $this->read_user($re[0]->id);
	}
	function verify_recaptcha_response($response,$remoteip)
	{
		$postfields['secret']=$this->config->item('recaptcha_secret');
		$postfields['response']=$response;
		if (!empty($remoteip)) $postfields['remoteip']=$remoteip;

		$recaptcha_endpoint='https://www.google.com/recaptcha/api/siteverify';

		$c = curl_init();
		curl_setopt($c,CURLOPT_URL, $recaptcha_endpoint);
		curl_setopt($c,CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($c,CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($c,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($c,CURLOPT_POST, 1);
		curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($postfields));
		$re = curl_exec($c);
		if (curl_error($c))
			return array('error'=>curl_error($c));
		return json_decode($re,true);
	}
	function create($p)
	{
		$salt=substr(rand(),3,11);

		$re=$this->db->query("insert into users(username,email,password,salt,fname,lname,registered) values (?,?,md5(concat(?,?)),?,?,?,current_timestamp)",array($p['username'],$p['email'],$p['password'],$salt,$salt,$p['fname'],$p['lname']));
		
		$dberror=$this->db->error()['message'];

		if (preg_match('/Duplicate.*username/',$dberror)) return "Username is already used";
		if (preg_match('/Duplicate.*email/',$dberror)) return "Email is already used";
		if ($dberror) return "Error creating user";
	}
	function check_user($value,$field)
	{
		if ($field!='email' and $field!='id') $field='username';
		$re=$this->db->query("select * from users where $field=?",array($value))->result();
		if (empty($re)) return null;
		$ps = $this->db->query('SELECT password FROM passwords WHERE user_id=?',[$re[0]->id])->row();
		if(!empty($ps))
		$re[0]->password = $ps->password;

		return $re[0];
	}
	function update_user($u,$p)
	{
		if (!is_object($u))
			$u=$this->db->query("select * from users where id=?",array($u))->result();
		if (empty($u)) return "Invalid user";
		if (is_array($u)) $u=$u[0];
		if (!empty($p->newpassword))
		{
			$p->salt=substr(rand(),3,11);
			$p->password=md5($p->newpassword.$p->salt);
		};
		$f=array_filter(array('username','email','fname','lname','password','salt'),function($e) use ($p,$u) {return !empty($p->{$e}) and $p->{$e}!=$u->{$e};});
		if (empty($f) and $u->o==$p->o) return "Nothing to update";
		$this->db->trans_start();
		$dberror="";
		if (!empty($f))
		{
			$q="update users set ".implode(', ',array_map(function($e) {return "$e=?";},$f)).' where id=?';

			$re=$this->db->query($q,array_merge(array_map(function($e) use ($p) {return $p->{$e};},$f),array($u->id)));
			$dberror=$this->db->error()['message'];
		};
		if ($dberror) return "Error updating user";
		if ($u->o != $p->o)
		{
			$this->db->query("delete from roles where user_id=?",array($u->id));
			$i=array_filter(array_map(function($e) {if (preg_match('/^occ-role-(\d+)/',$e,$m)) return $m[1]; return -1;},array_keys((array)$p)),function($e) {return $e>0;});
			if (!empty($i))
			{
				$q="insert into roles (user_id,role,journal_id) values ".implode(', ',array_map(function($e){return "(?,?,?)";},$i));
				$v=array();
				foreach ($i as $i) { $v[]=$u->id;$v[]=$p->{"occ-role-$i"}; $v[]=$p->{"occ-journal-$i"}; };
				$re=$this->db->query($q,$v);
				$dberror=$this->db->error()['message'];
			};
		};
		if ($dberror) return "Error updating user";
		$this->db->trans_complete();
		if (!empty($p->newpassword)) $this->db->query("update passwords set password=? where user_id=?",array($p->newpassword,$u->id));
	}
	function approve_user($user_id=null,$jid=null)
	{
		$this->db->set('approved', 'current_timestamp', FALSE);
		$this->db->where(array('user_id' => $user_id, 'journal_id' => $jid));
		$this->db->update('roles');
		return $this->db->error()['message'];
	}
	function disapprove_user($user_id=null,$jid=null)
	{
		$this->db->set('approved', 'null', FALSE);
		$this->db->where(array('user_id' => $user_id, 'journal_id' => $jid));
		$this->db->update('roles');
		return $this->db->error()['message'];
	}
	function delete_user($user_id=null)
	{
		$this->db->set('deleted', 'current_timestamp', FALSE);
		$this->db->where('id', $user_id);
		$this->db->update('users');
		return $this->db->error()['message'];
	}
	function restore_user($user_id=null)
	{
		$this->db->set('deleted', 'null', FALSE);
		$this->db->where('id', $user_id);
		$this->db->update('users');
		return $this->db->error()['message'];
	}
	function user_occupations($user_id)
	{
		return $this->db->query("select r.* from roles r join journals j on j.j_id=r.journal_id where user_id=? order by role,j_name",array($user_id))->result();
	}
	function lookup($term,$req=[])
	{
		$w=explode(' ',trim($term));
		$p="";
		$v=array();
		if (count($w)==1) {$p="u.fname like concat('%',?,'%') or u.lname like concat('%',?,'%')"; $v=array($w[0],$w[0]); };
		if (count($w)>1) {$p="u.fname like concat('%',?,'%') and u.lname like concat('%',?,'%')"; $v=array($w[0],$w[1]); };
		$join='';
		if (!empty($req['journal_id']) or !empty($req['role'])) $join.='join roles r on r.user_id=u.id';
		if (!empty($req['journal_id'])) { $join.=' and r.journal_id=?'; array_splice($v,0,0,$req['journal_id']); };
		if (!empty($req['role']) and strlen($req['role'])==1) { $join.=' and r.role=?'; $i=empty($req['journal_id'])?0:1;array_splice($v,$i,0,$req['role']); };
		if (!empty($req['role']) and strlen($req['role'])>1) { $join.=" and ? like concat('%',r.role,'%')"; $i=empty($req['journal_id'])?0:1;array_splice($v,$i,0,$req['role']); };
		$limit='';
		if (!empty($req['limit'])) $limit=sprintf("limit %d",$req['limit']);

		$select='';
		if (!empty($req['get_status']))
			$select.=",(select count(distinct m.id) from manuscript_assignments a join manuzcripts m on m.id=a.manuscript_id where a.user_id=u.id and a.role=r.role and m.status not in ('Rejected','Withdrawn','Ready to Publish')) as status";

		$re=$this->db->query($q="select u.* $select from users u $join where $p order by u.fname,u.lname $limit",$v)->result();
		return $re;
	}
	function user_status($user_id)
	{
		$re=$this->db->query("select a.role,count(distinct m.id) as manuscripts from manuscript_assignments a join manuzcripts m on m.id=a.manuscript_id where a.user_id=? and m.status not in ('Rejected','Withdrawn','Ready to Publish') group by a.role order by 1",[$user_id])->result();
		return $re;
	}
	public function addRole($data)
	{
		$this->db->insert('roles', $data);
		return $this->db->error()['message'];
	}
	public function addJournal($id,$role,$data)
	{
		$this->db->trans_start();

		foreach ($data as $journal_id)
			$this->db->query("insert into roles(user_id,role,journal_id) values (?,?,?)",[$id, $role, $journal_id]);
		
		$dberror=$this->db->error()['message'];
		if($dberror) return $dberror;

		$this->db->trans_complete();
	}

	function availability($field, $value)
	{
		return $this->db->query("SELECT COUNT(".$field.") AS counting FROM users WHERE ".$field."='".$value."'")->result()[0]->counting;
	}
}
?>
