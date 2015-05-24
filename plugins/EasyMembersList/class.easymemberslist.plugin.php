<?php
if(!defined('APPLICATION')) die();

$PluginInfo['EasyMembersList'] = array(
	'Name' => 'Easy Members List',
	'Description' => 'Show members list in vanilla forums. Link position and allowed users are configurable.',
	'Version' => '0.2.3',
	'RequiredApplications' => array('Vanilla' => '2.1a1'),
	'RequiredTheme' => FALSE,
	'RequiredPlugins' => FALSE,
	'SettingsUrl' => 'settings/easymemberslist',
	'SettingsPermission' => 'Garden.Settings.Manage',
	'Author' => "Alessandro Miliucci",
	'AuthorEmail' => 'lifeisfoo@gmail.com',
	'AuthorUrl' => 'http://forkwait.net',
	'License' => 'GPL v3'
);

class EasyMembersListPlugin extends Gdn_Plugin{
  //TODO: add others place to show link
  //TODO: role based "show only to" configuration
  //TODO: show a default "page not found" when user is not allowed to see list

  public function SettingsController_EasyMembersList_Create($Sender) {
    $Sender->Permission('Garden.Plugins.Manage');
    $Sender->AddSideMenu();
    $Sender->Title('Easy Members List');
    $ConfigurationModule = new ConfigurationModule($Sender);
    $ConfigurationModule->RenderAll = True;
    $Schema = array(
        'Plugins.EasyMembersList.ShowLinkInMenu' => 
        array('LabelCode' => T('Show members list link in menu'), 
              'Control' => 'CheckBox', 
              'Default' => C('Plugins.EasyMembersList.ShowLinkInMenu', '1')
        ),
        'Plugins.EasyMembersList.ShowLinkInFlyout' => 
        array('LabelCode' => T('Show members list link in account option flyout'), 
              'Control' => 'CheckBox', 
              'Default' => C('Plugins.EasyMembersList.ShowLinkInFlyout', '1')
        ),
        'Plugins.EasyMembersList.ShowToGuests' => 
        array('LabelCode' => T('Show members list to guest'), 
              'Control' => 'CheckBox', 
              'Default' => C('Plugins.EasyMembersList.ShowToGuests', '1')
        ),
        'Plugins.EasyMembersList.ShowEmail' => 
        array('LabelCode' => T('Show email address column'), 
                           'Control' => 'CheckBox', 
              'Default' => C('Plugins.EasyMembersList.ShowEmail', '0')
        ),
        'Plugins.EasyMembersList.ShowOnlyToTheseUsers' => 
        array('LabelCode' => T('Show members list only to these users (comma separated usernames). Guest configuration is not affected by this.'), 
              'Control' => 'TextBox',
              'Options' => array('Multiline' => TRUE),
              'Default' => C('Plugins.EasyMembersList.ShowOnlyToTheseUsers', '')
        ),
        'Plugins.EasyMembersList.HideTheseUsers' => 
        array('LabelCode' => T('Hide these users from being listed (comma separated usernames)'), 
              'Control' => 'TextBox',
              'Options' => array('Multiline' => TRUE),
              'Default' => C('Plugins.EasyMembersList.HideTheseUsers', '')
        )
    );
    $ConfigurationModule->Schema($Schema);
    $ConfigurationModule->Initialize();
    $Sender->View = dirname(__FILE__) . DS . 'views' . DS . 'easy_members_list_settings.php';
    $Sender->ConfigurationModule = $ConfigurationModule;
    $Sender->Render();
  }

  /**
   * Return an array of trimmed strings. Also removes empty strings.
   */
  private function trimNames($Names){
      $NamesTrimmed = array();
      foreach($Names as $Name){
          $TrimmedName = trim($Name);
          if($TrimmedName && $TrimmedName != ''){
              array_push($NamesTrimmed, trim($Name));
          }
      }
      return $NamesTrimmed;
  }
  
  //check before show link and before show page
  //check permission in settings
  private function isUserAllowed($Sender){
    $UserName = Gdn::Session()->User->Name;
    if(!$UserName){    //if user is guest check conf and make show/hide
      if(C('Plugins.EasyMembersList.ShowToGuests', '0') == '1'){
	return true;
      }else{
	return false;
      }
    }else{
      //else (user not guest) check if the list is empty (show)
      $ArrUsers = explode(',', C('Plugins.EasyMembersList.ShowOnlyToTheseUsers', ''));
      $ArrUsersTrimmed = $this->trimNames($ArrUsers);
      //if list is not empty check if username is in list
      if(count($ArrUsersTrimmed) != 0){
          if(in_array($UserName, $ArrUsersTrimmed)){
              return true;
          }else{
              return false;
          }
      }else{//show to all members
          return true;
      }
      //return true => ok, show | false => ko, hide
    }
  }

  public function Base_Render_Before($Sender){//check settings
    if( C('Plugins.EasyMembersList.ShowLinkInMenu', '0') == '1' ){
      if(self::isUserAllowed($Sender)  && $Sender->Menu){
          $Sender->Menu->AddLink('Members', T('Members list'), 'members');
      }
    }
  }
  
  public function MeModule_FlyoutMenu_Handler($Sender){
    if( C('Plugins.EasyMembersList.ShowLinkInFlyout', '0') == '1' ){
      if(self::isUserAllowed($Sender)){
          echo Wrap(Anchor(Sprite('SpMembersList').' '.T('Members list'), 'members'), 'li');
      }
    }
  }

  public function PluginController_EasyMembersList_Create($Sender){
    if(self::isUserAllowed($Sender)){
      $Sender->ClearCssFiles();
      $Sender->AddCssFile('style.css');
      //TODO: use the new secure path mode without explicit plugin directory name
      $Sender->AddCssFile('/plugins/EasyMembersList/design/easy_members_list.css');
      $Sender->MasterView = 'default';

      $Names = explode(',', C('Plugins.EasyMembersList.HideTheseUsers', ''));
      $TrimmedNames = $this->trimNames($Names);
      if( count($TrimmedNames) != 0){
          $Sender->UserData = Gdn::SQL()->Select('User.*')->From('User')->OrderBy('User.Name')->Where('Deleted',false)->WhereNotIn('Name',$TrimmedNames)->Get();
      }else{
          $Sender->UserData = Gdn::SQL()->Select('User.*')->From('User')->OrderBy('User.Name')->Where('Deleted',false)->Get();
      }
      RoleModel::SetUserRoles($Sender->UserData->Result());

      $Sender->ShowEmail = C('Plugins.EasyMembersList.ShowEmail', '0');
      $Sender->Render(dirname(__FILE__) . DS . 'views' . DS . 'easy_members_list.php');
    }
  }
  
  public function Setup() {
    $this->Structure();
  }

  public function OnDisable() {
    Gdn::Router()->DeleteRoute('members');
  }
  
  public function Structure() {
    Gdn::Router()->SetRoute('members', '/plugin/EasyMembersList', 'Internal');
  }
  

}
?>
