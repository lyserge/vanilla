<?php
if(!defined('APPLICATION')) die();

?>
<h1><?php echo T('Members list'); ?></h1>
<h2><?php printf(T('%s members'), count($this->UserData->Result()));?> </h2>
<table class="EasyMembersListTable" style="width: 100%;">
   <thead>
      <tr>
         <th><?php echo T('Username'); ?></th>
         <th><?php echo T('Roles'); ?></th>
<?php
if($this->ShowEmail == 1) {
    echo '<th>' . T('Email') . '</th>';
}
?>
         <th><?php echo T('First Visit'); ?></th>
         <th><?php echo T('Last Visit'); ?></th>
      </tr>
   </thead>
   <tbody>
   <?php
   $Alt = FALSE;
   foreach ($this->UserData->Result() as $User) {
     $Alt = $Alt ? FALSE : TRUE;
     ?>
     <tr<?php echo $Alt ? ' class="Alt"' : ''; ?>>
        <td class="UserName"><strong><?php echo UserAnchor($User); ?></strong></td>
        <td class="UserRoles">
           <?php
           $Roles = GetValue('Roles', $User, array());
           $RolesString = '';

           if ($User->Banned && !in_array('Banned', $Roles)) {
              $RolesString = T('Banned');
           }

           foreach ($Roles as $RoleID => $RoleName) {
              $RolesString = ConcatSep(', ', $RolesString, htmlspecialchars($RoleName));
           }
           echo $RolesString;
           ?>
        </td>
<?php
           if($this->ShowEmail == 1) {
               echo '<td class="UserEmail">' . $User->Email . '</td>';
           }
?>
        <td class="UserFirstVisit"><?php echo Gdn_Format::Date($User->DateFirstVisit, 'html'); ?></td>
        <td class="UserLastVisit"><?php echo Gdn_Format::Date($User->DateLastActive, 'html'); ?></td>
     </tr>
     <?php } ?>
   </tbody>
</table>
