<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User extends CI_Controller {

	public function index()
	{
		echo "string";
	}

	public function register()
	{

		$this->load->model('user_model','user');
		$dbErr = $this->user->create($this->input->post());
		
		if(!empty($dbErr))
			die($dbErr);

		$flash = array(
			'icon'=>'success',
			'btn'=>'true',
			'title' => 'Registered',
			'text' => "Please follow the instructions sent you in the email at ".$this->input->post('email').".",
			'timer' => 'false'
		);

		$this->session->set_flashdata('cryptoFlash', (object)$flash);

		redirect($_SERVER['HTTP_REFERER']);
	}

	public function login($value='')
	{
		# code...
	}
	public function availability($login_check = '')
	{

		$field = array_keys($this->input->post())[0];
		$value = $this->input->post($field);

		$this->load->model('user_model','user');

		if($this->user->availability($field,$value) > 0)
			echo ($login_check == 'login') ? 'true' : 'false';
		else
			echo ($login_check == 'login') ? 'false' : 'true';
	}
}
