<?php
/**
 * Custom repeater control for the Freshsales field mapping.
 *
 * Unlike Elementor Pro's Fields_Map (which lists the remote field on the left and a
 * form-field dropdown on the right), this control lists each of the form's own fields on
 * the left with a Freshsales-field dropdown on the right — clearer for new users.
 *
 * The stored item shape stays { local_id, remote_id }, identical to Fields_Map, so the
 * server-side mapping in Freshsales_Action::run() is unchanged and existing maps survive.
 *
 * @package Cornerstone\Elementor_Freshsales
 */

namespace Cornerstone\Elementor_Freshsales;

use Elementor\Control_Repeater;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Freshsales_Map_Control extends Control_Repeater {

	const CONTROL_TYPE = 'cornerstone_freshsales_map';

	public function get_type() {
		return self::CONTROL_TYPE;
	}

	protected function get_default_settings() {
		return array_merge(
			parent::get_default_settings(),
			array(
				'render_type' => 'none',
				'fields'      => array(
					array(
						'name' => 'local_id',
						'type' => Controls_Manager::HIDDEN,
					),
					array(
						'name' => 'remote_id',
						'type' => Controls_Manager::SELECT,
					),
				),
			)
		);
	}
}
