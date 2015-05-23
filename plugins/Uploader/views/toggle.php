<div class="UploaderBlock Slice" rel="/plugin/Uploader/toggle">
   <?php
      echo $this->Form->Open();
   
      $UploaderStatus = $this->Data['UploaderStatus'];
      $NewUploadStatus = !$this->Data['UploaderStatus'];
      
      function ParseBoolStatus($BoolStatus) {
         return ($BoolStatus) ? 'ON' : 'OFF';
      }
   
      $UploaderStatus = ParseBoolStatus($UploaderStatus);
      $NewUploadStatus = ParseBoolStatus($NewUploadStatus);
      
      echo $this->Form->Hidden('UploaderStatus', array('value' => $NewUploadStatus));
   ?>
   <ul>
      <li><?php echo $this->Form->Label("Uploader is currently {$UploaderStatus}"); ?></li>
   </ul>
   <?php 
      echo $this->Form->Close("Turn {$NewUploadStatus}",'',array(
         'class' => 'SliceSubmit Button'
      )); 
   ?>
</div>