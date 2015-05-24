<?php
// Define the plugin:
$PluginInfo['ShowDiscussionAuthor'] = array(
   'Description' => 'Shows discussion author in discussion summary',
   'Version' => '1.3',
   'Author' => "Robert Ivanov",
   'AuthorEmail' => 'rb@robi-bobi.net',
   'AuthorUrl' => 'http://www.robi-bobi.net'
);
/* contributions by:
Jason Barnabe - XSS fix
*/

class ShowDiscussionAuthor implements Gdn_IPlugin {
   
   public function DiscussionsController_DiscussionMeta_Handler(&$Sender) {
	   $Discussion = $Sender->EventArguments['Discussion'];
	   echo '<span class="ShowDiscussionAuthor"> '. T('Author') .
         ': <a href="'.Url('/profile/'.$Discussion->FirstUserID.'/'.
         urlencode($Discussion->FirstName)).'">'.htmlspecialchars($Discussion->FirstName).'</a></span>';
	    
	   return;
   }

   public function CategoriesController_DiscussionMeta_Handler(&$Sender) {
      $this->DiscussionsController_DiscussionMeta_Handler($Sender);
   }
   
   public function Setup() {
      //no setup needed
   }
} 
