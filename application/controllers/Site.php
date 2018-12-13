<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Site extends CI_Controller {

	public function index()
	{
		$this->load->view('landing-page');
	}
	public function about()
	{
		$this->load->view('about');
	}
}
