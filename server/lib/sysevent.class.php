<?php
/**
 * System events from server to client
 *
 * @package stalker_portal
 * @author zhurbitsky@gmail.com
 */

class SysEvent extends Event
{
    /**
     * Send "message" event
     *
     * @param string $msg
     */
    public function sendMsg($msg){
        $this->setEvent('send_msg');
        $this->setNeedConfirm(1);
        $this->setMsg($msg);
        $this->send();
    }
    
    /**
     * Send "message and reboot after OK" event
     *
     * @param string $msg
     */
    public function sendMsgAndReboot($msg){
        $this->setEvent('send_msg');
        $this->setNeedConfirm(1);
        $this->setMsg($msg);
        $this->setRebootAfterOk(1);
        $this->send();
    }
    
    /**
     * Send "update subscription" event
     */
    public function sendUpdateSubscription(){
        $this->sendUpdateChannels();
    }
    
    /**
     * Send "update channels" event
     */
    public function sendUpdateChannels(){
        $this->setEvent('update_subscription');
        $this->send();
    }
    
    /**
     * Send "mount all storages" event
     */
    public function sendMountAllStorages(){
        $this->setEvent('mount_all_storages');
        $master = new VideoMaster();
        $this->setMsg(json_encode($master->getStoragesForStb()));
        $this->send();
    }
    
    /**
     * Send "play channel" event
     *
     * @param int $ch_num
     */
    public function sendPlayChannel($ch_num){
        $this->setEvent('play_channel');
        $this->setMsg($ch_num);
        $this->send();
    }
    
    /**
     * Send "cut off" event
     */
    public function sendCutOff(){
        $this->setEvent('cut_off');
        $this->send();
    }
    
    /**
     * Send "cut on" event
     */
    public function sendCutOn(){
        $this->setEvent('cut_on');
        $this->send();
    }
    
    /**
     * Send "reset paused" event
     */
    public function sendResetPaused(){
        $this->setEvent('show_menu');
        $this->send();
    }
    
    /**
     * Send "reboot" event
     */
    public function sendReboot(){
        $this->setEvent('reboot');
        $this->send();
    }
    
    /**
     * Send "additional services status" event 
     *
     * @param int $status must be 1 or 0
     */
    public function sendAdditionalServicesStatus($status = 1){
        $this->setEvent('additional_services_status');
        $this->setMsg($status);
        $this->send();
    }
    
    /**
     * Send "updated places" event
     *
     * @param string $place must be 'vclub' or 'anec'
     */
    public function sendUpdatedPlaces($place = 'vclub'){
        $this->setEvent('updated_places');
        $this->setMsg($place);
        $this->send();
    }
}

?>