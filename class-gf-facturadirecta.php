<?php

GFForms::include_feed_addon_framework();

class GFFD extends GFFeedAddOn {

	protected $_version = GF_FD_VERSION;
	protected $_min_gravityforms_version = '1.9.0';
	protected $_slug = 'gravityformsfd';
	protected $_path = 'gravityformsfd/facturadirecta.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'FacturaDirecta Add-On';
	protected $_short_title = 'FacturaDirecta';

	// Members plugin integration
	protected $_capabilities = array( 'gravityforms_fd', 'gravityforms_fd_uninstall' );

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_fd';
	protected $_capabilities_form_settings = 'gravityforms_fd';
	protected $_capabilities_uninstall = 'gravityforms_fd_uninstall';
	protected $_enable_rg_autoupgrade = true;

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFFD();
		}

		return self::$_instance;
	}

	public function init() {

		parent::init();
        //loading translations
            load_plugin_textdomain('gravityformsfd', FALSE, '/gravityforms-facturadirecta/languages' );

	}

	public function init_admin(){
		parent::init_admin();

		$this->ensure_upgrade();
	}

	// ------- Plugin settings -------
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => __( 'FacturaDirecta Account Information', 'gravityformsfd' ),
				'description' => __( 'Use this connector with FacturaDirecta software. Use Gravity Forms to collect customer information and automatically add them to your FacturaDirecta Clients.', 'gravityformsfd' ),
				'fields'      => array(
					array(
						'name'              => 'gf_fd_accountname',
						'label'             => __( 'Account Name', 'gravityformsfd' ),
						'type'              => 'text',
						'class'             => 'medium',
					),
					array(
						'name'              => 'gf_fd_username',
						'label'             => __( 'Username', 'gravityformsfd' ),
						'type'              => 'text',
						'class'             => 'medium',
					),
					array(
						'name'  => 'gf_fd_password',
						'label' => __( 'Password', 'gravityformsfd' ),
						'type'  => 'api_key',
						'class' => 'medium',
                        'tooltip'       => __( 'Use the password of the actual user.', 'gravityformsfd' ),
                        'tooltip_class'     => 'tooltipclass',
						'feedback_callback' => $this->login_api_fd()
					),
				)
			),
		);
	}



	public function settings_api_key( $field, $echo = true ) {

		$field['type'] = 'text';

		$api_key_field = $this->settings_text( $field, false );

		//switch type="text" to type="password" so the key is not visible
		$api_key_field = str_replace( 'type="text"','type="password"', $api_key_field );

		$caption = '<small>' . sprintf( __( "Use the Password of your account.", 'gravityformsfd' ) ) . '</small>';

		if ( $echo ) {
			echo $api_key_field . '</br>' . $caption;
		}

		return $api_key_field . '</br>' . $caption;
	}


	//-------- Form Settings ---------
	public function feed_edit_page( $form, $feed_id ) {

		// ensures valid credentials were entered in the settings page
		if ( $this->login_api_fd() == false ) {
			?>
			<div><?php echo sprintf( __( 'We are unable to login to FacturaDirecta with the provided API key or URL is incorrect (it must finish with slash / ). Please make sure you have entered a valid API key in the %sSettings Page%s', 'gravityformsfd' ),
					'<a href="' . $this->get_plugin_settings_url() . '">', '</a>' ); ?>
			</div>
			<?php
			return;
		}

		echo '<script type="text/javascript">var form = ' . GFCommon::json_encode( $form ) . ';</script>';

		parent::feed_edit_page( $form, $feed_id );
	}


	public function feed_settings_fields() {
		return array(
			array(
				'title'       => __( 'FacturaDirecta Feed', 'gravityformsfd' ),
				'description' => '',
				'fields'      => array(
					array(
						'name'     => 'feedName',
						'label'    => __( 'Name', 'gravityformsfd' ),
						'type'     => 'text',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => '<h6>' . __( 'Name', 'gravityformsfd' ) . '</h6>' . __( 'Enter a feed name to uniquely identify this setup.', 'gravityformsfd' ),
					),
					array(
						'name'       => 'listFields',
						'label'      => __( 'Map Fields', 'gravityformsfd' ),
						'type'       => 'field_map',
						//'dependency' => 'contactList',
						'field_map'	 => $this->create_list_field_map(),
						'tooltip'    => '<h6>' . __( 'Map Fields', 'gravityformsfd' ) . '</h6>' . __( 'Associate your Facturadirecta custom fields to the appropriate Gravity Form fields by selecting the appropriate form field from the list.', 'gravityformsfd' ),
					),
				)
			),
		);
	}

	public function create_list_field_map() {

		$custom_fields = $this->get_custom_fields_fd( );

		return $custom_fields;

	}

	public function get_custom_fields_fd( ) {

	    $settings = $this->get_plugin_settings();
	    if (isset($settings['gf_fd_accountname']) ) $accountname = $settings['gf_fd_accountname']; else $accountname="";
	    if (isset($settings['gf_fd_apipassword']) ) $apipassword = $settings['gf_fd_apipassword']; else $apipassword="";

        $custom_fields = $this->facturadirecta_listfields($accountname, $apipassword);

        $this->debugcrm($custom_fields);

		return $custom_fields;
	}


	public function feed_list_columns() {
		return array(
			'feedName'		=> __( 'Name', 'gravityformsfd' )
		);
	}

	public function ensure_upgrade(){

		if ( get_option( 'gf_fd_upgrade' ) ){
			return false;
		}

		$feeds = $this->get_feeds();
		if ( empty( $feeds ) ){

			//Force Add-On framework upgrade
			$this->upgrade( '2.0' );
		}

		update_option( 'gf_fd_upgrade', 1 );
	}

	public function process_feed( $feed, $entry, $form ) {

		if ( ! $this->is_valid_key() ) {
			return;
		}

		$this->export_feed( $entry, $form, $feed );

	}

	public function export_feed( $entry, $form, $feed ) {

		//$email       = $entry[ $feed['meta']['listFields_email'] ];
		//$name        = '';
		if ( ! empty( $feed['meta']['listFields_first_name'] ) ) {
			$name = $this->get_name( $entry, $feed['meta']['listFields_first_name'] );
		}

		$merge_vars = array();
		$field_maps = $this->get_field_map_fields( $feed, 'listFields' );

		foreach ( $field_maps as $var_key => $field_id ) {
			$field = RGFormsModel::get_field( $form, $field_id );
			if ( GFCommon::is_product_field( $field['type'] ) && rgar( $field, 'enablePrice' ) ) {
				$ary          = explode( '|', $entry[ $field_id ] );
				$product_name = count( $ary ) > 0 ? $ary[0] : '';
				$merge_vars[] = array( 'name' => $var_key, 'value' => $product_name );
			} else if ( RGFormsModel::get_input_type( $field ) == 'checkbox' ) {
				foreach ( $field['inputs'] as $input ) {
					$index = (string) $input['id'];
					$merge_vars[] = array(
						'name'   => $var_key,
						'value' => apply_filters( 'gform_crm_field_value', rgar( $entry, $index ), $form['id'], $field_id, $entry )
					);
				}
			} else  {
				$merge_vars[] = array(
					'name'   => $var_key,
					'value' => apply_filters( 'gform_crm_field_value', rgar( $entry, $field_id ), $form['id'], $field_id, $entry )
				);
			}
		}

		$override_custom_fields = apply_filters( 'gform_crm_override_blank_custom_fields', false, $entry, $form, $feed );
		if ( ! $override_custom_fields ){
			$merge_vars = $this->remove_blank_custom_fields( $merge_vars );
		}



        $settings = $this->get_plugin_settings();
	    if (isset($settings['gf_fd_accountname']) ) $accountname = $settings['gf_fd_accountname']; else $accountname="";
	    if (isset($settings['gf_fd_apipassword']) ) $apipassword = $settings['gf_fd_apipassword']; else $apipassword="";

        $id = $this->facturadirecta_createlead($accountname, $apipassword, $merge_vars);

        //Sends email if it does not create a lead
        //if ($id == false)
        //    $this->send_emailerrorlead($crm_type);
        $this->debugcrm($id);
}

    private function send_emailerrorlead($crm_type) {
        // Sends email if it does not create a lead

        $subject = __('We could not create the lead in ','gravityformsfd').$crm_type;
        $message = __('<p>There was a problem creating the lead in the CRM.</p><p>Try to find where it was the problem in the Wordpress Settings.</p><br/><p><strong>Gravity Forms CRM</strong>','gravityformsfd');

        wp_mail( get_bloginfo('admin_email'), $subject, $message);
    }
	private static function remove_blank_custom_fields( $merge_vars ){
		$i=0;

		$count = count( $merge_vars );

		for ( $i = 0; $i < $count; $i++ ){
            if( rgblank( $merge_vars[$i]['value'] ) ){
				unset( $merge_vars[$i] );
			}
		}
		//resort the array because items could have been removed, this will give an error from CRM if the keys are not in numeric sequence
		sort( $merge_vars );
		return $merge_vars;
	}

	private function get_name( $entry, $field_id ) {

		//If field is simple (one input), simply return full content
		$name = rgar( $entry, $field_id );
		if ( ! empty( $name ) ) {
			return $name;
		}

		//Complex field (multiple inputs). Join all pieces and create name
		$prefix = trim( rgar( $entry, $field_id . '.2' ) );
		$first  = trim( rgar( $entry, $field_id . '.3' ) );
		$last   = trim( rgar( $entry, $field_id . '.6' ) );
		$suffix = trim( rgar( $entry, $field_id . '.8' ) );

		$name = $prefix;
		$name .= ! empty( $name ) && ! empty( $first ) ? " $first" : $first;
		$name .= ! empty( $name ) && ! empty( $last ) ? " $last" : $last;
		$name .= ! empty( $name ) && ! empty( $suffix ) ? " $suffix" : $suffix;

		return $name;
	}

    private function is_valid_key(){
        $result_api = $this->login_api_fd();

        return $result_api;
    }

    private function login_api_fd(){

    $settings = $this->get_plugin_settings();

    if (isset($settings['gf_fd_username']) ) $username = $settings['gf_fd_username']; else $username = "";
    if (isset($settings['gf_fd_password']) ) $password = $settings['gf_fd_password']; else $password="";
    if (isset($settings['gf_fd_accountname']) ) $accountname = $settings['gf_fd_accountname']; else $accountname="";

    $login_result = $this->facturadirecta_login($accountname,$username, $password);

    $this->debugcrm($login_result);

    if (!isset($login_result) )
        $login_result="";
    return $login_result;
    }

//////////// Helpers Functions ////////////

    private function facturadirecta_login($accountname, $username, $password) {
		$settings = $this->get_plugin_settings();

		$this->debugcrm($settings);

		if (isset($settings['gf_fd_apipassword']) ) {
			$authkey = $settings['gf_fd_apipassword'];
		} else {
	        $url = 'https://'.$accountname.'.facturadirecta.com/api/login.xml';
	        $param = "u=".$username."&p=".$password;
	        $ch = curl_init();
	        curl_setopt($ch, CURLOPT_URL, $url);
	        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/xml'));
	        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
	        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	        curl_setopt($ch, CURLOPT_POST, 1);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
	        $result = curl_exec($ch);
	        $info = curl_getinfo($ch);
	        $doc = new DomDocument();
	        $doc->loadXML($result);
	        curl_close($ch);

	        $tokenId = $doc->getElementsByTagName("token")->item(0)->nodeValue;
	        if(!empty($tokenId)){
	            $authkey = $tokenId;
	        }
	        else{
	            $authkey = "0";
	        }

			$settings['gf_fd_apipassword'] = $authkey;
			$this->update_plugin_settings($settings);
		}
		return $authkey;
    }
    private function facturadirecta_listfields($accountname, $token) {
        $agent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)';
        $url = "https://".$accountname.".facturadirecta.com/api/clients.xml?api_token=".$token;
        $param = $token.":x";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/xml'));
        //curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $param);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_PUT, true);
        $result = curl_exec($ch);
        curl_close($ch);
        //echo $result;
        $p = xml_parser_create();
        xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
        xml_parse_into_struct($p, $result, $vals, $index);
        xml_parser_free($p);
        $level1tag="";
        $level2tag="";
        $level3tag="";
        $duedatecounter=0;
        foreach ($vals as $key=>$val) {
            //echo "\n".$val['tag']."\n";
            if($val['tag']=="client")
                continue;
            if($val['level']>=2){
                //echo $val['level'];
                if($val['type']=="open" && $val['level']==2){
                    $level1tag =$val['tag'].".";
                }
                else if ($val['type']=="close" && $val['level']==2){
                    $level1tag ="";
                }
                if($val['type']=="open" && $val['level']==3){
                    $level2tag =$val['tag'].".";
                }
                else if ($val['type']=="close" && $val['level']==3){
                    $level2tag ="";
                }
                if($val['type']=="open" && $val['level']==4){
                    $level3tag =$val['tag'].".";
                }
                else if ($val['type']=="close" && $val['level']==4){
                    $level3tag ="";
                }
            }
            $req=FALSE;
            if($val['level']=="2" &&$val['tag']=="name"){
                $req=TRUE;
            }
            if($val['type']=="complete"){
                $taglabel=str_replace(".", " ",$level1tag).str_replace(".", " ",$level2tag).str_replace(".", " ",$level3tag);
                $tagname=$level1tag.$level2tag.$level3tag;
                if($tagname == "billing.dueDates.dueDate."){
                    if($val['tag']=="delayInDays")
                        $duedatecounter=$duedatecounter+1;
                    $tagname=$level1tag.$level2tag.str_replace(".", $duedatecounter,$level3tag).".";
                    $taglabel=$taglabel.$duedatecounter." ";
                }
                if($tagname == "customAttributes.customAttribute."){
                    if($val['tag']=="label")
                        $fields[]=array(
                        'label' => $taglabel.$val['value'],
                        'name' =>  $tagname.$val['value'],
                        'required' => $req
                    );
                }
                else{
                    $fields[]=array(
                        'label' => $taglabel.$val['tag'],
                        'name' =>  $tagname.$val['tag'],
                        'required' => $req
                    );
                }
            }
        }
        return $fields;
    }
    private function facturadirecta_createlead($accountname, $token, $mergevars){
        $agent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)';
        $url = "https://".$accountname.".facturadirecta.com/api/clients.xml?api_token=".$token;
        $param = $token.":x";
        $xml = $this->get_cleintxmlforcreate($mergevars);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $param);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        $response = curl_exec($ch);
        $doc = new DomDocument();
        $doc->loadXML($response);
        //echo "Response:<pre>";
        //print_r($doc->textContent);
        //echo "</pre>";
        curl_close($ch);
        $itemId = $doc->getElementsByTagName("id")->item(0)->nodeValue;
        $httpStatus = $doc->getElementsByTagName("httpStatus")->item(0)->nodeValue;
        if(!empty($httpStatus)){
            $returnValue = '0';
        }else{
            $returnValue = $itemId;
        }
        return $returnValue;
    }
    private function get_cleintxmlforcreate($mergevars){
        $xml  = "<?xml version='1.0' encoding='UTF-8'?>";
        $xml .= "<client>";
        // Obtain a list of columns
        foreach ($mergevars as $key => $row) {
            $akey[$key]  = $row['name'];
        }
        // Sort the data with volume descending, edition ascending
        // Add $data as the last parameter, to sort by the common key
        array_multisort($akey, SORT_ASC, $mergevars);
        //echo "<pre>";
        //print_r($mergevars);
        //echo "</pre>";
        $i=0;
        $count = count( $mergevars);
        $firstlevel="";
        $address="";
        $bank="";
        $billing="";
        $billingtax1="";
        $billingtax2="";
        $billingtax3="";
        $billingtax4="";
        $billingtax5="";
        $billingtax6="";
        $dueDate1="";
        $dueDate2="";
        $dueDate3="";
        $dueDate4="";
        for ( $i = 0; $i < $count; $i++ ){
            $field=$mergevars[$i]['name'];
            $fieldvalue=$mergevars[$i]['value'];
            $fieldvalues = explode(".",$field);
            $fieldLevel = count($fieldvalues);
            //if($fieldLevel==1)
            //    echo "<".$field."><![CDATA[".$fieldvalue."]]><".$field.">";
            if($fieldLevel>0){
                //echo "<owner><id><![CDATA[".$fieldvalue."]]><".$field.">";
                $xmlNode="";
                for($j = $fieldLevel-1; $j>= 0; $j--){
                    if($j == $fieldLevel-1){
                        if($fieldvalues[0]=="address"){
                            if( $fieldvalues[$j]!="address")
                                $address .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                        }
                        if($fieldvalues[0]=="customAttributes"){
                            if( $fieldvalues[$j]!="customAttributes" && $fieldvalues[$j]!="customAttribute")
                                $customAttributes .= "<customAttribute><label><![CDATA[".$fieldvalues[$j]."]]></label>";
                                $customAttributes .= "<value><![CDATA[".$fieldvalue."]]></value></customAttribute>";
                        }
                        elseif ($fieldvalues[0]=="billing"){
                            if( $fieldvalues[$j]!="billing"){

                                if($fieldLevel==2)
                                    $billing .= "<".$fieldvalues[1]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[1].">";
                                elseif ($fieldvalues[1]=="bank"){
                                    if( $fieldvalues[$j]!="bank")
                                        $bank .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                }
                                elseif (($field=="billing.tax1.name" && $fieldvalues[$j]="name")||($field=="billing.tax1.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax1 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif(($field=="billing.tax2.name" && $fieldvalues[$j]="name")||($field=="billing.tax2.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax2 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif(($field=="billing.tax3.name" && $fieldvalues[$j]="name")||($field=="billing.tax3.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax3 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif(($field=="billing.tax4.name" && $fieldvalues[$j]="name")||($field=="billing.tax4.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax4 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif(($field=="billing.tax5.name" && $fieldvalues[$j]="name")||($field=="billing.tax5.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax5 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif(($field=="billing.tax6.name" && $fieldvalues[$j]="name")||($field=="billing.tax6.rate" && $fieldvalues[$j]="rate"))
                                    $billingtax6 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                elseif ($fieldvalues[1]=="dueDates"){
                                    if( $fieldvalues[$j]!="dueDates"){
                                        if(($field=="billing.dueDates.dueDate1.delayInDays" && $fieldvalues[$j]="delayInDays")||($field=="billing.dueDates.dueDate1.rate" && $fieldvalues[$j]="rate"))
                                            $dueDate1 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                        if(($field=="billing.dueDates.dueDate2.delayInDays" && $fieldvalues[$j]="delayInDays")||($field=="billing.dueDates.dueDate2.rate" && $fieldvalues[$j]="rate"))
                                            $dueDate2 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                        if(($field=="billing.dueDates.dueDate3.delayInDays" && $fieldvalues[$j]="delayInDays")||($field=="billing.dueDates.dueDate3.rate" && $fieldvalues[$j]="rate"))
                                            $dueDate3 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                        if(($field=="billing.dueDates.dueDate4.delayInDays" && $fieldvalues[$j]="delayInDays")||($field=="billing.dueDates.dueDate4.rate" && $fieldvalues[$j]="rate"))
                                            $dueDate4 .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                                    }
                                }
                            }
                        }
                        else
                            $xmlNode .= "<".$fieldvalues[$j]."><![CDATA[".$fieldvalue."]]></".$fieldvalues[$j].">";
                    }
                    else {
                        if( $fieldvalues[$j]=="address" || $fieldvalues[0]=="billing" || $fieldvalues[0]=="customAttributes")
                            continue;
                        $xmlNode = "<".$fieldvalues[$j].">".$xmlNode."</".$fieldvalues[$j].">";
                    }
                }
                $xml .= $xmlNode;
            }
        }
        if($address!="")
            $xml .= "<address>".$address."</address>";
        if($bank!="")
            $billing .= "<bank>".$bank."</bank>";
        if($billingtax1!="")
            $billingtax1 = "<tax1>".$billingtax1."</tax1>";
        if($billingtax2!="")
            $billingtax2 = "<tax2>".$billingtax2."</tax2>";
        if($billingtax3!="")
            $billingtax3 = "<tax3>".$billingtax3."</tax3>";
        if($billingtax4!="")
            $billingtax4 = "<tax4>".$billingtax4."</tax4>";
        if($billingtax5!="")
            $billingtax5 = "<tax5>".$billingtax5."</tax5>";
        if($billingtax6!="")
            $billingtax6 = "<tax6>".$billingtax6."</tax6>";
        $billing.=$billingtax1.$billingtax2.$billingtax3.$billingtax4.$billingtax5.$billingtax6;
        if($dueDate1!="")
            $dueDate1 = "<dueDate>".$dueDate1."</dueDate>";
        if($dueDate2!="")
            $dueDate2 = "<dueDate>".$dueDate2."</dueDate>";
        if($dueDate3!="")
            $dueDate3 = "<dueDate>".$dueDate3."</dueDate>";
        if($dueDate4!="")
            $dueDate4 = "<dueDate>".$dueDate4."</dueDate>";
        $dueDates=$dueDate1.$dueDate2.$dueDate3.$dueDate4;
        if($dueDates!="")
            $billing .= "<dueDates>".$dueDates."</dueDates>";
        if($billing!="")
            $xml .= "<billing>".$billing."</billing>";

        if(isset($customAttributes)&& ($customAttributes!="") )
            $xml .= "<customAttributes>".$customAttributes."</customAttributes>";

        $xml .= "</client>";
        //echo $xml;
        return $xml;
    }


    ////////////////////////////////

    private function debugcrm($message) {
            if (WP_DEBUG==true) {
            //Debug Mode
            echo '  <table class="widefat">
                    <thead>
                    <tr class="form-invalid">
                        <th class="row-title">'.__('Message Debug Mode','gravityformsfd').'</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                    <td><pre>';
            print_r($message);
            echo '</pre></td></tr></table>';
        }
    }


} //from main class
