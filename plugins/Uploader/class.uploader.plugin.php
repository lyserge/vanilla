<?php if (!defined('APPLICATION')) exit();
// Define the plugin:
$PluginInfo['Uploader'] = array(
   'Description' => 'Upload and attach files and images. Based on the classic FileUpload plugin by @Todd',
   'Version' => '1.4',
   'RequiredTheme' => FALSE,
   'RequiredPlugins' => FALSE,
   'HasLocale' => FALSE,
   'MobileFriendly' => TRUE,
   'RegisterPermissions' => array(
   'Plugins.Attachments.Upload.Allow' => 'Garden.Profiles.Edit',
   'Plugins.Attachments.Download.Allow' => 'Garden.Profiles.Edit'),
   'SettingsUrl' => '/settings/Uploader',
   'SettingsPermission' => 'Garden.Settings.Manage',
   'License'=>"GNU GPL2",
   'Author' => "VrijVlinder",

);

include dirname(__FILE__).'/class.mediamodel.php';

class UploaderPlugin extends Gdn_Plugin {

   protected $_MediaCache;


   public function __construct() {
      parent::__construct();
      $this->_MediaCache = NULL;
      $this->CanUpload = Gdn::Session()->CheckPermission('Plugins.Attachments.Upload.Allow', FALSE);
      $this->CanDownload = Gdn::Session()->CheckPermission('Plugins.Attachments.Download.Allow', FALSE);

      if ($this->CanUpload) {
         $PermissionCategory = $this->PermissionCategory(Gdn::Controller()->Data('Category'));
         if (!GetValue('AllowUploaders', $PermissionCategory, TRUE))
            $this->CanUpload = FALSE;
      }
   }

  // added this
  public static function PermissionCategory($Category) {
      if (empty($Category))
         return CategoryModel::Categories(-1);

      if (!is_array($Category) && !is_object($Category)) {
         $Category = CategoryModel::Categories($Category);
      }

      return CategoryModel::Categories(GetValue('PermissionCategoryID', $Category));
   }


    public function AssetModel_StyleCss_Handler($Sender) {
      $Sender->AddCssFile('uploader.css', 'plugins/Uploader');
   }

   public function MediaCache() {
      if ($this->_MediaCache === NULL) {
         $this->CacheAttachedMedia(Gdn::Controller());
      }
      return $this->_MediaCache;
   }


   public function MediaModel() {
      static $MediaModel = NULL;

      if ($MediaModel === NULL) {
         $MediaModel = new MediaModel();
      }
      return $MediaModel;
   }

   /**
    * Adds a settings page to edit the allowed file extensions
    */
    public function SettingsController_Uploader_Create($Sender) {
        $Sender->Permission('Garden.Settings.Manage');
        $Sender->AddSideMenu('/settings/Uploader');
        $Sender->SetData('Title', T('Uploader Configuration'));

        $Conf = new ConfigurationModule($Sender);
        $Conf->Schema(array(
            'AllowedFileExtensions' => array(
                'Control' => 'textbox',
                'LabelCode' => 'Allowed File Extensions (comma separated)'
            ))
        );

        if ($Sender->Form->AuthenticatedPostBack()) {
            $Values = $Sender->Form->FormValues();
            $Extensions = array_map('trim', explode(',', val('AllowedFileExtensions', $Values)));
            SaveToConfig('Garden.Upload.AllowedFileExtensions', $Extensions);
            $Sender->InformMessage(T('Your settings have been saved.'));
        } else {
            $List = implode(', ', C('Garden.Upload.AllowedFileExtensions'));
            $Sender->Form->SetValue('AllowedFileExtensions', $List);
        }

        $Conf->RenderAll();
    }

   public function Base_GetAppSettingsMenuItems_Handler($Sender) {
      $Menu =$Sender->EventArguments['SideMenu'];
     $Menu->AddLink('Add-ons','Uploader', 'settings/Uploader', 'Garden.Settings.Manage');
   }

   public function PluginController_Uploader_Create($Sender) {
      $Sender->Title('Uploader');
      $Sender->AddSideMenu('plugin/Uploader');
      Gdn_Theme::Section('Dashboard');
      $Sender->Form = new Gdn_Form();

      $this->EnableSlicing($Sender);
      $this->Dispatch($Sender, $Sender->RequestArgs);
   }

   public function Controller_Delete($Sender) {
      list($Action, $MediaID) = $Sender->RequestArgs;
      $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);

      $Delete = array(
         'MediaID'   => $MediaID,
         'Status'    => 'failed'
      );

      $Media = $this->MediaModel()->GetID($MediaID);
      $ForeignTable = GetValue('ForeignTable', $Media);
      $Permission = FALSE;

      // Get the category so we can figure out whether or not the user has permission to delete.
      if ($ForeignTable == 'discussion') {
         $PermissionCategoryID = Gdn::SQL()
            ->Select('c.PermissionCategoryID')
            ->From('Discussion d')
            ->Join('Category c', 'd.CategoryID = c.CategoryID')
            ->Where('d.DiscussionID', GetValue('ForeignID', $Media))
            ->Get()->Value('PermissionCategoryID');
         $Permission = 'Vanilla.Discussions.Edit';
      } elseif ($ForeignTable == 'comment') {
         $PermissionCategoryID = Gdn::SQL()
            ->Select('c.PermissionCategoryID')
            ->From('Comment cm')
            ->Join('Discussion d', 'd.DiscussionID = cm.DiscussionID')
            ->Join('Category c', 'd.CategoryID = c.CategoryID')
            ->Where('cm.CommentID', GetValue('ForeignID', $Media))
            ->Get()->Value('PermissionCategoryID');
         $Permission = 'Vanilla.Comments.Edit';
      }

      if ($Media) {
         $Delete['Media'] = $Media;
         $UserID = GetValue('UserID', Gdn::Session());
         if (GetValue('InsertUserID', $Media, NULL) == $UserID || Gdn::Session()->CheckPermission($Permission, TRUE, 'Category', $PermissionCategoryID)) {
            $this->MediaModel()->Delete($Media, TRUE);
            $Delete['Status'] = 'success';
         } else {
            throw PermissionException();
         }
      } else {
         throw NotFoundException('Media');
      }

      $Sender->SetJSON('Delete', $Delete);
      $Sender->Render($this->GetView('blank.php'));
   }

   public function DiscussionController_Render_Before($Sender) {
      $this->PrepareController($Sender);
   }

   public function PostController_Render_Before($Sender) {
      $this->PrepareController($Sender);
   }

   protected function PrepareController($Controller) {
      if (!$this->IsEnabled()) return;

      $Controller->AddJsFile('uploader.js', 'plugins/Uploader');
      $Controller->AddDefinition('apcavailable',self::ApcAvailable());
      $Controller->AddDefinition('uploaderuniq',uniqid(''));

      $PostMaxSize = Gdn_Upload::UnformatFileSize(ini_get('post_max_size'));
      $FileMaxSize = Gdn_Upload::UnformatFileSize(ini_get('upload_max_filesize'));
      $ConfigMaxSize = Gdn_Upload::UnformatFileSize(C('Garden.Upload.MaxFileSize', '1MB'));
      $MaxSize = min($PostMaxSize, $FileMaxSize, $ConfigMaxSize);
      $Controller->AddDefinition('maxuploadsize',$MaxSize);
   }

   public function PostController_AfterDiscussionFormOptions_Handler($Sender) {
      if (!is_null($Discussion = GetValue('Discussion',$Sender, NULL))) {
         $Sender->EventArguments['Type'] = 'Discussion';
         $Sender->EventArguments['Discussion'] = $Discussion;
         $this->AttachUploadsToComment($Sender, 'discussion');
      }
      $this->DrawAttachFile($Sender);
   }

   public function DiscussionController_BeforeFormButtons_Handler($Sender) {
      $this->DrawAttachFile($Sender);
   }

   public function DrawAttachFile($Sender) {
      if (!$this->IsEnabled()) return;
      if (!$this->CanUpload) return;

      echo $Sender->FetchView('attach_file', '', 'plugins/Uploader');
   }


   protected function CacheAttachedMedia($Sender) {
      if (!$this->IsEnabled()) return;

      $Comments = $Sender->Data('Comments');
      $CommentIDList = array();

      if ($Comments instanceof Gdn_DataSet && $Comments->NumRows()) {
         $Comments->DataSeek(-1);
         while ($Comment = $Comments->NextRow())
            $CommentIDList[] = $Comment->CommentID;
      } elseif (isset($Sender->Discussion) && $Sender->Discussion) {
         $CommentIDList[] = $Sender->DiscussionID = $Sender->Discussion->DiscussionID;
      }
      if (isset($Sender->Comment) && isset($Sender->Comment->CommentID)) {
         $CommentIDList[] = $Sender->Comment->CommentID;
      }

      if (count($CommentIDList)) {
         $DiscussionID = $Sender->Data('Discussion.DiscussionID');

         $MediaData = $this->MediaModel()->PreloadDiscussionMedia($DiscussionID, $CommentIDList);
      } else {
         $MediaData = FALSE;
      }

      $MediaArray = array();
      if ($MediaData !== FALSE) {
         $MediaData->DataSeek(-1);
         while ($Media = $MediaData->NextRow()) {
            $MediaArray[$Media->ForeignTable.'/'.$Media->ForeignID][] = $Media;
         }
      }

      $this->_MediaCache = $MediaArray;
   }

   public function DiscussionController_AfterCommentBody_Handler($Sender, $Args) {
      if (isset($Args['Type']))
         $this->AttachUploadsToComment($Sender, strtolower($Args['Type']));
      else
         $this->AttachUploadsToComment($Sender);
   }

   public function DiscussionController_AfterDiscussionBody_Handler($Sender) {
      $this->AttachUploadsToComment($Sender, 'discussion');
   }

   public function PostController_AfterCommentBody_Handler($Sender) {
      $this->AttachUploadsToComment($Sender);
   }

   public function SettingsController_AddEditCategory_Handler($Sender) {
      $Sender->Data['_PermissionFields']['AllowUploaders'] = array('Control' => 'CheckBox');
   }


   protected function AttachUploadsToComment($Controller, $Type = 'comment') {
      if (!$this->IsEnabled()) return;

      //$Type = strtolower($RawType = $Controller->EventArguments['Type']);
      $RawType = ucfirst($Type);

      if (StringEndsWith($Controller->RequestMethod, 'Comment', TRUE) && $Type != 'comment') {
         $Type = 'comment';
         $RawType = 'Comment';
         if (!isset($Controller->Comment))
            return;
         $Controller->EventArguments['Comment'] = $Controller->Comment;
      }

      $MediaList = $this->MediaCache();
      if (!is_array($MediaList)) return;

      $Param = (($Type == 'comment') ? 'CommentID' : 'DiscussionID');
      $MediaKey = $Type.'/'.GetValue($Param, GetValue($RawType, $Controller->EventArguments));
      if (array_key_exists($MediaKey, $MediaList)) {
         include_once $Controller->FetchViewLocation('Uploader_functions', '', 'plugins/Uploader');

         $Controller->SetData('CommentMediaList', $MediaList[$MediaKey]);
         $Controller->SetData('GearImage', $this->GetWebResource('images/gear.png'));
         $Controller->SetData('Garbage', $this->GetWebResource('images/trash.png'));
         $Controller->SetData('CanDownload', $this->CanDownload);
         echo $Controller->FetchView($this->GetView('link_files.php'));
      }
   }

   public function DiscussionController_Download_Create($Sender) {
      if (!$this->IsEnabled()) return;
      if (!$this->CanDownload) throw PermissionException("@You need to <a href='/entry/signin'>log in</a> to download that file.");

      list($MediaID) = $Sender->RequestArgs;
      $Media = $this->MediaModel()->GetID($MediaID);

      if (!$Media) return;

      $Filename = Gdn::Request()->Filename();
      if (!$Filename || $Filename == 'default') $Filename = $Media->Name;

      $DownloadPath = CombinePaths(array(MediaModel::PathUploads(),GetValue('Path', $Media)));

      if (in_array(strtolower(pathinfo($Filename, PATHINFO_EXTENSION)), array('bmp', 'gif', 'jpg', 'jpeg', 'png')))
         $ServeMode = 'inline';
      else
         $ServeMode = 'attachment';

      $Served = FALSE;
      $this->EventArguments['DownloadPath'] = $DownloadPath;
      $this->EventArguments['ServeMode'] = $ServeMode;
      $this->EventArguments['Media'] = $Media;
      $this->EventArguments['Served'] = &$Served;
      $this->FireEvent('BeforeDownload');

      if (!$Served) {
         return Gdn_FileSystem::ServeFile($DownloadPath, $Filename, $Media->Type, $ServeMode);
         throw new Exception('File could not be streamed: missing file ('.$DownloadPath.').');
      }

      exit();
   }

   public function PostController_AfterCommentSave_Handler($Sender, $Args) {
      if (!$Args['Comment']) return;

      $CommentID = $Args['Comment']->CommentID;
      if (!$CommentID) return;

      $AttachedFilesData = Gdn::Request()->GetValue('AttachedUploads');
      $AllFilesData = Gdn::Request()->GetValue('AllUploads');

      $this->AttachAllFiles($AttachedFilesData, $AllFilesData, $CommentID, 'comment');
   }

   public function PostController_AfterDiscussionSave_Handler($Sender, $Args) {
      if (!$Args['Discussion']) return;

      $DiscussionID = $Args['Discussion']->DiscussionID;
      if (!$DiscussionID) return;

      $AttachedFilesData = Gdn::Request()->GetValue('AttachedUploads');
      $AllFilesData = Gdn::Request()->GetValue('AllUploads');

      $this->AttachAllFiles($AttachedFilesData, $AllFilesData, $DiscussionID, 'discussion');
   }

   public function LogModel_AfterInsert_Handler($Sender, $Args) {
      // Only trigger if logging unapproved discussion or comment
      $Log = GetValue('Log', $Args);
      $Type = strtolower(GetValue('RecordType', $Log));
      $Operation = GetValue('Operation', $Log);
      if (!in_array($Type, array('discussion', 'comment')) || $Operation != 'Pending')
         return;

      // Attach file to the log entry
      $LogID = GetValue('LogID', $Args);
      $AttachedFilesData = Gdn::Request()->GetValue('AttachedUploads');
      $AllFilesData = Gdn::Request()->GetValue('AllUploads');

      $this->AttachAllFiles($AttachedFilesData, $AllFilesData, $LogID, 'log');
   }


   public function LogModel_AfterRestore_Handler($Sender, $Args) {
      $Log = GetValue('Log', $Args);

      // Only trigger if restoring discussion or comment
      $Type = strtolower(GetValue('RecordType', $Log));
      if (!in_array($Type, array('discussion', 'comment')))
         return;

      // Reassign media records from log entry to newly inserted content
      $this->MediaModel()->Reassign(GetValue('LogID', $Log), 'log', GetValue('InsertID', $Args), $Type);
   }


   protected function AttachAllFiles($AttachedFilesData, $AllFilesData, $ForeignID, $ForeignTable) {
      if (!$this->IsEnabled()) return;

      // No files attached
      if (!$AttachedFilesData) return;

      $SuccessFiles = array();
      foreach ($AttachedFilesData as $FileID) {
         $Attached = $this->AttachFile($FileID, $ForeignID, $ForeignTable);
         if ($Attached)
            $SuccessFiles[] = $FileID;
      }

      // clean up failed and unattached files
      $DeleteIDs = array_diff($AllFilesData, $SuccessFiles);
      foreach ($DeleteIDs as $DeleteID) {
         $this->TrashFile($DeleteID);
      }
   }


   public function UtilityController_Thumbnail_Create($Sender, $Args = array()) {
      $MediaID = array_shift($Args);
      if (!is_numeric($MediaID))
         array_unshift($Args, $MediaID);
      $SubPath = implode('/', $Args);
      $Name = $SubPath;
      $Parsed = Gdn_Upload::Parse($Name);

      // Get actual path to the file.
      $Path = Gdn_Upload::CopyLocal($SubPath);
      if (!file_exists($Path))
         throw NotFoundException('File');

      // Figure out the dimensions of the upload.
      $ImageSize = getimagesize($Path);
      $SHeight = $ImageSize[1];
      $SWidth = $ImageSize[0];

      if (!in_array($ImageSize[2], array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG))) {
         if (is_numeric($MediaID)) {
            // Fix the thumbnail information so this isn't requested again and again.
            $Model = new MediaModel();
            $Media = array('MediaID' => $MediaID, 'ImageWidth' => 0, 'ImageHeight' => 0, 'ThumbPath' => NULL);
            $Model->Save($Media);
         }

         $Url = Asset('/plugins/Uploader/design/images/file.png');
         Redirect($Url, 301);
      }

      $Options = array();

      $ThumbHeight = MediaModel::ThumbnailHeight();
      $ThumbWidth = MediaModel::ThumbnailWidth();

      if (!$ThumbHeight || $SHeight < $ThumbHeight) {
         $Height = $SHeight;
         $Width = $SWidth;
      } else {
         $Height = $ThumbHeight;
         $Width = round($Height * $SWidth / $SHeight);
      }

      if ($ThumbWidth && $Width > $ThumbWidth) {
         $Width = $ThumbWidth;

         if (!$ThumbHeight) {
            $Height = round($Width * $SHeight / $SWidth);
         } else {
            $Options['Crop'] = TRUE;
         }
      }

      $TargetPath = "thumbnails/{$Parsed['Name']}";
      $ThumbParsed = Gdn_UploadImage::SaveImageAs($Path, $TargetPath, $Height, $Width, $Options);
      // Cleanup if we're using a scratch copy
      if ($ThumbParsed['Type'] != '' || $Path != MediaModel::PathUploads().'/'.$SubPath)
         @unlink($Path);

      if (is_numeric($MediaID)) {
         // Save the thumbnail information.
         $Model = new MediaModel();
         $Media = array('MediaID' => $MediaID, 'ThumbWidth' => $Width, 'ThumbHeight' => $Height, 'ThumbPath' => $ThumbParsed['SaveName']);
         $Model->Save($Media);
      }

      $Url = $ThumbParsed['Url'];
      Redirect($Url, 301);

   }


   protected function AttachFile($FileID, $ForeignID, $ForeignType) {
      $Media = $this->MediaModel()->GetID($FileID);
      if ($Media) {
         $Media->ForeignID = $ForeignID;
         $Media->ForeignTable = $ForeignType;
         try {

            $this->MediaModel()->Save($Media);
         } catch (Exception $e) {
            die($e->getMessage());
            return FALSE;
         }
         return TRUE;
      }
      return FALSE;
   }


   protected function PlaceMedia(&$Media, $UserID) {
      $NewFolder = UploaderPlugin::FindLocalMediaFolder($Media->MediaID, $UserID, TRUE, FALSE);
      $CurrentPath = array();
      foreach ($NewFolder as $FolderPart) {
         array_push($CurrentPath, $FolderPart);
         $TestFolder = CombinePaths($CurrentPath);

         if (!is_dir($TestFolder) && !@mkdir($TestFolder, 0775, TRUE))
            throw new Exception("Failed creating folder '{$TestFolder}' during PlaceMedia verification loop");
      }

      $FileParts = pathinfo($Media->Name);
      $SourceFilePath = CombinePaths(array($this->PathUploads(),$Media->Path));
      $NewFilePath = CombinePaths(array($TestFolder,$Media->MediaID.'.'.$FileParts['extension']));
      $Success = rename($SourceFilePath, $NewFilePath);
      if (!$Success)
         throw new Exception("Failed renaming '{$SourceFilePath}' -> '{$NewFilePath}'");

      $NewFilePath = UploaderPlugin::FindLocalMedia($Media, FALSE, TRUE);
      $Media->Path = $NewFilePath;

      return TRUE;
   }


   public static function FindLocalMediaFolder($MediaID, $UserID, $Absolute = FALSE, $ReturnString = FALSE) {
      $DispersionFactor = C('Plugin.Uploader.DispersionFactor',20);
      $FolderID = $MediaID % $DispersionFactor;
      $ReturnArray = array('Uploader',$FolderID);

      if ($Absolute)
         array_unshift($ReturnArray, MediaModel::PathUploads());

      return ($ReturnString) ? implode(DS,$ReturnArray) : $ReturnArray;
   }


   public static function FindLocalMedia($Media, $Absolute = FALSE, $ReturnString = FALSE) {
      $ArrayPath = UploaderPlugin::FindLocalMediaFolder($Media->MediaID, $Media->InsertUserID, $Absolute, FALSE);

      $FileParts = pathinfo($Media->Name);
      $RealFileName = $Media->MediaID.'.'.$FileParts['extension'];
      array_push($ArrayPath, $RealFileName);

      return ($ReturnString) ? implode(DS, $ArrayPath) : $ArrayPath;
   }


   public function PostController_Upload_Create($Sender) {
      if (!$this->IsEnabled()) return;

      list($FieldName) = $Sender->RequestArgs;

      $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);
      include_once $Sender->FetchViewLocation('uploader_functions', '', 'plugins/Uploader');

      $Sender->FieldName = $FieldName;
      $Sender->ApcKey = Gdn::Request()->GetValueFrom(Gdn_Request::INPUT_POST,'APC_UPLOAD_PROGRESS');

      // this will hold the IDs and filenames of the items we were sent.
      $MediaResponse = array();

      $FileData = Gdn::Request()->GetValueFrom(Gdn_Request::INPUT_FILES, $FieldName, FALSE);
      try {
         if (!$this->CanUpload)
            throw new UploaderPluginUploadErrorException("You do not have permission to upload files",11,'???');

         if (!$Sender->Form->IsPostBack()) {
            $PostMaxSize = ini_get('post_max_size');
            throw new UploaderPluginUploadErrorException("The post data was too big (max {$PostMaxSize})",10,'???');
         }

         if (!$FileData) {
            //$PostMaxSize = ini_get('post_max_size');
            $MaxUploadSize = ini_get('upload_max_filesize');
            //throw new UploaderPluginUploadErrorException("The uploaded file was too big (max {$MaxUploadSize})",10,'???');
            throw new UploaderPluginUploadErrorException("No file data could be found in your post",10,'???');
         }

         // Validate the file upload now.
         $FileErr  = $FileData['error'];
         $FileType = $FileData['type'];
         $FileName = $FileData['name'];
         $FileTemp = $FileData['tmp_name'];
         $FileSize = $FileData['size'];
         $FileKey  = ($Sender->ApcKey ? $Sender->ApcKey : '');

         if ($FileErr != UPLOAD_ERR_OK) {
            $ErrorString = '';
            switch ($FileErr) {
               case UPLOAD_ERR_INI_SIZE:
                  $MaxUploadSize = ini_get('upload_max_filesize');
                  $ErrorString = sprintf(T('The uploaded file was too big (max %s).'), $MaxUploadSize);
                  break;
               case UPLOAD_ERR_FORM_SIZE:
                  $ErrorString = 'The uploaded file was too big';
                  break;
               case UPLOAD_ERR_PARTIAL:
                  $ErrorString = 'The uploaded file was only partially uploaded';
                  break;
               case UPLOAD_ERR_NO_FILE:
                  $ErrorString = 'No file was uploaded';
                  break;
               case UPLOAD_ERR_NO_TMP_DIR:
                  $ErrorString = 'Missing a temporary folder';
                  break;
               case UPLOAD_ERR_CANT_WRITE:
                  $ErrorString = 'Failed to write file to disk';
                  break;
               case UPLOAD_ERR_EXTENSION:
                  $ErrorString = 'A PHP extension stopped the file upload';
                  break;
            }

            throw new UploaderPluginUploadErrorException($ErrorString, $FileErr, $FileName, $FileKey);
         }

         // Analyze file extension
         $FileNameParts = pathinfo($FileName);
         $Extension = strtolower($FileNameParts['extension']);
         $AllowedExtensions = C('Garden.Upload.AllowedFileExtensions', array("*"));
         if (!in_array($Extension, $AllowedExtensions) && !in_array('*',$AllowedExtensions))
            throw new UploaderPluginUploadErrorException("Uploaded file type is not allowed.", 11, $FileName, $FileKey);

         // Check upload size
         $MaxUploadSize = Gdn_Upload::UnformatFileSize(C('Garden.Upload.MaxFileSize', '1G'));
         if ($FileSize > $MaxUploadSize) {
            $Message = sprintf(T('The uploaded file was too big (max %s).'), Gdn_Upload::FormatFileSize($MaxUploadSize));
            throw new UploaderPluginUploadErrorException($Message, 11, $FileName, $FileKey);
         }

         // Build filename
         $SaveFilename = md5(microtime()).'.'.strtolower($Extension);
         $SaveFilename = '/Uploader/'.substr($SaveFilename, 0, 2).'/'.substr($SaveFilename, 2);

         // Get the image size before doing anything.
         list($ImageWidth, $ImageHeight) = Gdn_UploadImage::ImageSize($FileTemp, $FileName);

         // Fire event for hooking save location
         $this->EventArguments['Path'] = $FileTemp;
         $Parsed = Gdn_Upload::Parse($SaveFilename);
         $this->EventArguments['Parsed'] =& $Parsed;
         $this->EventArguments['OriginalFilename'] = $FileName;
         $Handled = FALSE;
         $this->EventArguments['Handled'] =& $Handled;
         $this->FireAs('Gdn_Upload')->FireEvent('SaveAs');
         $SavePath = $Parsed['Name'];

         if (!$Handled) {
            // Build save location
            $SavePath = MediaModel::PathUploads().$SaveFilename;
            if (!is_dir(dirname($SavePath)))
               @mkdir(dirname($SavePath), 0775, TRUE);
            if (!is_dir(dirname($SavePath)))
               throw new UploaderPluginUploadErrorException("Internal error, could not save the file.", 9, $FileName);

            // Move to permanent location
            $MoveSuccess = @move_uploaded_file($FileTemp, $SavePath);
            if (!$MoveSuccess)
               throw new UploaderPluginUploadErrorException("Internal error, could not move the file.", 9, $FileName);
         } else {
            $SaveFilename = $Parsed['SaveName'];
         }

         // Save Media data
         $Media = array(
            'Name'            => $FileName,
            'Type'            => $FileType,
            'Size'            => $FileSize,
            'ImageWidth'      => $ImageWidth,
            'ImageHeight'     => $ImageHeight,
            'InsertUserID'    => Gdn::Session()->UserID,
            'DateInserted'    => date('Y-m-d H:i:s'),
            'StorageMethod'   => 'local',
            'Path'            => $SaveFilename
         );
         $MediaID = $this->MediaModel()->Save($Media);
         $Media['MediaID'] = $MediaID;

         $FinalImageLocation = '';
         $PreviewImageLocation = MediaModel::ThumbnailUrl($Media);



         $MediaResponse = array(
            'Status'             => 'success',
            'MediaID'            => $MediaID,
            'Filename'           => $FileName,
            'Filesize'           => $FileSize,
            'FormatFilesize'     => Gdn_Format::Bytes($FileSize,1),
            'ProgressKey'        => $Sender->ApcKey ? $Sender->ApcKey : '',
            'Thumbnail' => base64_encode(MediaThumbnail($Media)),
            'FinalImageLocation' => Url(MediaModel::Url($Media)),
            'Parsed' => $Parsed
         );

      } catch (UploaderPluginUploadErrorException $e) {

         $MediaResponse = array(
            'Status'          => 'failed',
            'ErrorCode'       => $e->getCode(),
            'Filename'        => $e->getFilename(),
            'StrError'        => $e->getMessage()
         );
         if (!is_null($e->getApcKey()))
            $MediaResponse['ProgressKey'] = $e->getApcKey();

         if ($e->getFilename() != '???')
            $MediaResponse['StrError'] = '('.$e->getFilename().') '.$MediaResponse['StrError'];
      } catch (Exception $Ex) {
         $MediaResponse = array(
            'Status'          => 'failed',
            'ErrorCode'       => $Ex->getCode(),
            'StrError'        => $Ex->getMessage()
         );
      }

      $Sender->SetJSON('MediaResponse', $MediaResponse);

      // Kludge: This needs to have a content type of text/* because it's in an iframe.
      ob_clean();
      header('Content-Type: text/html');
      echo json_encode($Sender->GetJson());
      die();

      $Sender->Render($this->GetView('blank.php'));
   }


   public function PostController_CheckUpload_Create($Sender) {
      list($ApcKey) = $Sender->RequestArgs;

      $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);

      $KeyData = explode('_',$ApcKey);
      array_shift($KeyData);
      $UploaderID = implode('_',$KeyData);

      $ApcAvailable = self::ApcAvailable();

      $Progress = array(
         'key'          => $ApcKey,
         'uploader'     => $UploaderID,
         'apc'          => ($ApcAvailable) ? 'yes' : 'no'
      );

      if ($ApcAvailable) {
         $UploadStatus = apc_fetch('upload_'.$ApcKey, $Success);

         if (!$Success)
            $UploadStatus = array(
               'current'   => 0,
               'total'     => -1
            );

         $Progress['progress'] = ($UploadStatus['current'] / $UploadStatus['total']) * 100;
         $Progress['total'] = $UploadStatus['total'];
         $Progress['format_total'] = Gdn_Format::Bytes($Progress['total'],1);
         $Progress['cache'] = $UploadStatus;
      }

      $Sender->SetJSON('Progress', $Progress);
      $Sender->Render($this->GetView('blank.php'));
   }

   public static function ApcAvailable() {
      $ApcAvailable = TRUE;
      if ($ApcAvailable && !ini_get('apc.enabled')) $ApcAvailable = FALSE;
      if ($ApcAvailable && !ini_get('apc.rfc1867')) $ApcAvailable = FALSE;

      return $ApcAvailable;
   }

   protected function TrashFile($MediaID) {
      $Media = $this->MediaModel()->GetID($MediaID);

      if ($Media) {
         $this->MediaModel()->Delete($Media);
         $Deleted = FALSE;

         // Allow interception
         $this->EventArguments['Parsed'] = Gdn_Upload::Parse($Media->Path);
         $this->EventArguments['Handled'] =& $Deleted; // Allow skipping steps below
         $this->FireEvent('TrashFile');

         if (!$Deleted) {
            $DirectPath = MediaModel::PathUploads().DS.$Media->Path;
            if (file_exists($DirectPath))
               $Deleted = @unlink($DirectPath);
         }

         if (!$Deleted) {
            $CalcPath = UploaderPlugin::FindLocalMedia($Media, TRUE, TRUE);
            if (file_exists($CalcPath))
               $Deleted = @unlink($CalcPath);
         }

      }
   }

   public function DiscussionModel_DeleteDiscussion_Handler($Sender) {
      $DiscussionID = $Sender->EventArguments['DiscussionID'];
      $this->MediaModel()->DeleteParent('Discussion', $DiscussionID);
   }

   public function CommentModel_DeleteComment_Handler($Sender) {
      $CommentID = $Sender->EventArguments['CommentID'];
      $this->MediaModel()->DeleteParent('Comment', $CommentID);
   }

   public function Setup() {
      $this->Structure();
      SaveToConfig('Plugins.Uploader.Enabled', TRUE);
   }

   public function Structure() {
      $Structure = Gdn::Structure();
      $Structure
         ->Table('Media')
         ->PrimaryKey('MediaID')
         ->Column('Name', 'varchar(255)')
         ->Column('Type', 'varchar(128)')
         ->Column('Size', 'int(11)')
         ->Column('ImageWidth', 'usmallint', NULL)
         ->Column('ImageHeight', 'usmallint', NULL)
         ->Column('StorageMethod', 'varchar(24)', 'local')
         ->Column('Path', 'varchar(255)')

         ->Column('ThumbWidth', 'usmallint', NULL)
         ->Column('ThumbHeight', 'usmallint', NULL)
         ->Column('ThumbPath', 'varchar(255)', NULL)

         ->Column('InsertUserID', 'int(11)')
         ->Column('DateInserted', 'datetime')
         ->Column('ForeignID', 'int(11)', TRUE)
         ->Column('ForeignTable', 'varchar(24)', TRUE)
         ->Set(FALSE, FALSE);

      $Structure
         ->Table('Category')
         ->Column('AllowUploaders', 'tinyint(1)', '1')
         ->Set();
   }

   public function OnDisable() {
      RemoveFromConfig('Plugins.Uploader.Enabled');
   }
}

class UploaderPluginUploadErrorException extends Exception {

   protected $Filename;
   protected $ApcKey;

   public function __construct($Message, $Code, $Filename, $ApcKey = NULL) {
      parent::__construct($Message, $Code);
      $this->Filename = $Filename;
      $this->ApcKey = $ApcKey;
   }

   public function getFilename() {
      return $this->Filename;
   }

   public function getApcKey() {
      return $this->ApcKey;
   }

}
