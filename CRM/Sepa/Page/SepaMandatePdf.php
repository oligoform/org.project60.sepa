<?php

require_once 'CRM/Core/Page.php';
require_once 'api/class.api.php';

class CRM_Sepa_Page_SepaMandatePdf extends CRM_Core_Page {

  function run() {
    $api = new civicrm_api3();
    $id = (int)CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $reference = CRM_Utils_Request::retrieve('ref', 'String', $this);
    //TODO: once debugged, force POST, not GET
    $action = CRM_Utils_Request::retrieve('pdfaction', 'String', $this);
    if ($id>0) {
      $api->SepaMandate->get($id);
    } elseif ($reference){ 
      $api->SepaMandate->get(array("reference"=>$reference));
    } else {
      CRM_Core_Error::fatal("missing parameter. you need id or ref of the mandate");
      return;
    }
    if ($api->is_error()) {
      CRM_Core_Error::fatal($api->errorMsg());
      return;
    }
    $mandate = $api->values[0];
    if ($mandate->entity_table != "civicrm_contribution_recur")
      return CRM_Core_Error::fatal("We don't know how to handle mandates for ".$mandate->entity_table);

    $api->ContributionRecur->getsingle(array("id"=>$mandate->entity_id));
    $recur=$api->result;
    $api->Contact->getsingle(array("id"=>$recur->contact_id));
    $contact=$api->result;

    $tpl= civicrm_api('OptionValue', 'getsingle', array('version' => 3,'option_group_name' => 'msg_tpl_workflow_contribution','name'=>'sepa_mandate_pdf'));
    if (array_key_exists("is_error",$tpl)) {
      sepa_civicrm_install_options(sepa_civicrm_options());
      $tpl= civicrm_api('OptionValue', 'getsingle', array('version' => 3,'option_group_name' => 'sepa_mandate_pdf','name'=>'sepa_mandate_pdf'));
    }

    $msg =  civicrm_api('MessageTemplates','getSingle',array("version"=>3,"workflow_id"=>$tpl["id"]));
    if (array_key_exists("is_error",$msg)) {
      $msg =  civicrm_api('MessageTemplates','create',array("version"=>3,"workflow_id"=>$tpl["id"],
            "msg_title"=>$tpl["label"],
            "is_reserved"=>1,
            "msg_html"=>"here be dragons"
            ));
    };

    CRM_Utils_System::setTitle($msg["msg_title"]  ." ". $mandate->reference);
    $this->assign("contact",(array) $contact);
    $this->assign("contactId",$contact->contact_id);
    $this->assign("sepa",(array) $mandate);
    $this->assign("recur",(array) $recur);

    $api->PaymentProcessor->getsingle((int)$recur->payment_processor_id);
    $pp=$api->result;
    $this->assign("creditor",$pp->user_name);

    $html = $this->getTemplate()->fetch("string:".$msg["msg_html"]);
    if ($action) {
      require_once 'CRM/Utils/PDF/Utils.php';
      $fileName = $mandate->reference.".pdf";
      if ($action == "email") {
        $config = CRM_Core_Config::singleton();
        $pdfFullFilename = $config->templateCompileDir . CRM_Utils_File::makeFileName($fileName);
        $pdfFullFilename = '/tmp/'.$fileName;
        file_put_contents($pdfFullFilename, CRM_Utils_PDF_Utils::html2pdf( $html,$fileName, true, null ));
        list($domainEmailName,$domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail();
        $params              = array();
        $params['groupName'] = 'SEPA Email Sender';
        $params['from']      = '"' . $domainEmailName . '" <' . $domainEmailAddress . '>';
        $params['toEmail'] = $contact->email;
        $params['toName']  = $params['toEmail'];
        //$params['toEmail'] = "debug@sydesy.com";

        if (empty ($params['toEmail'])){
          CRM_Core_Session::setStatus(ts("Error sending $fileName: Contact doesn't have an email."));
          return false;
        }
        $params['subject'] = "SEPA " . $fileName;
        if (!CRM_Utils_Array::value('attachments', $instanceInfo)) {
          $instanceInfo['attachments'] = array();
        }
        $params['attachments'][] = array(
            'fullPath' => $pdfFullFilename,
            'mime_type' => 'application/pdf',
            'cleanName' => $fileName,
            );
        ;
        $params['text'] = "this is the mandate, please return signed";
        //    $params['html'] = $template->msg_text;
        CRM_Utils_Mail::send($params);
        CRM_Core_Session::setStatus(ts("Mail sent"));



      }  else {
        CRM_Utils_PDF_Utils::html2pdf( $html, $fileName, false, null );
        CRM_Utils_System::civiExit();
      } 
    }

    $this->assign("html",$html);
    parent::run();
  }
}
