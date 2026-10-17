<?php 

namespace App;


class Messages {

    static $messages = array();

    static function  create() {
        Messages::$messages[0] = new Message("loginFailed","These credentials do not match our records","البيانات المدخلة لا تتوافق مع أي من السجلات ");
        Messages::$messages[1] = new Message("floorNumberExistsInBuilding","Floor number already exists in building","رقم الدور هذا موجود مسبقا في هذا المبنى");
        Messages::$messages[2] = new Message("suiteNumberExistsInFloor","Suite number already exists in floor","رقم الجناح هذا موجود مسبقا في هذا الدور");
        Messages::$messages[3] = new Message("roomNumberExistsInSuite","Room number already exists in suite","رقم الغرفة هذا موجود مسبقا في هذا الجناح");
        Messages::$messages[4] = new Message("noActiveReservation","No active reservation for you now","ليس لديك حجز فعال حالياً");
    }

    public static function getMessage(string $id) {
        if (count(Messages::$messages) == 0) {
            Messages::create();
        }
        foreach (Messages::$messages as $m) {
            if ($m->id == $id) {
                return $m->arabic;
            }
        }
        return "e";
    }

}