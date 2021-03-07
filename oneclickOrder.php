<?php
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader,
    Bitrix\Main\Context,
    Bitrix\Currency\CurrencyManager,
    Bitrix\Sale\Order,
    Bitrix\Sale\Basket,
    Bitrix\Sale\Delivery,
    Bitrix\Sale\PaySystem;


$order = new OneClickOrder();
$order->execute();

class OneClickOrder
{

    protected $errors = array();
    private $requestFieldsArray;
    private $requiredFields = array("name", "email", "phone", "last_name");
    private $order = null;
    private $basket = null;
    const EVENT_ID = "USER_MAKE_ONECLICK_ORDER";
    const CUSTOM_USER_ID = 8;
    const SUCCESS_MESSAGE = "Спасибо за ваш заказ";

    public function __construct()
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');
        Loader::includeModule('currency');
        $this->requestFieldsArray = Context::getCurrent()->getRequest()->getPostList()->toArray();
        $this->siteId = Context::getCurrent()->getSite();
        $this->currencyCode = CurrencyManager::getBaseCurrency();
    }

    private function clearData(array &$fields)
    {
        foreach ($fields as &$field) {
            $field = htmlspecialchars(trim($field));
        }
    }

    private function checkRequestFields(array $fields)
    {
        $this->clearData($this->requestFieldsArray);

        foreach ($fields as $name => $field) {
            if (in_array($name, $this->requiredFields)
                && $field == "") {
                $this->errors[0] = "Заполните обязательные поля";
            }
        }
        if (!empty($fields["email"]) && filter_var($fields["email"], FILTER_VALIDATE_EMAIL) === false) {
            array_push($this->errors, "Вы ввели некорректный email");
        }
        if (!empty($fields["phone"])) {
            $pattern = "/^\+[\d\(\)\ -]{9,23}\d$/";
            if (!preg_match($pattern, $fields["phone"])) {
                array_push($this->errors, "Вы ввели некорректный номер телефона. Номер должен быть в международном формате.");

            }
        }
    }

    private function createNewOrder()
    {
        global $USER;
        $this->order = Order::create($this->siteId, $USER->isAuthorized() ? $USER->GetID() : self::CUSTOM_USER_ID);
        $this->order->setPersonTypeId(1);
        $this->order->setField('CURRENCY', $this->currencyCode);
        if ($this->requestFieldsArray["comment"]) {
            $this->order->setField('USER_DESCRIPTION', $this->requestFieldsArray["comment"]); // Устанавливаем поля комментария покупателя
        }
    }

    private function createBasket()
    {
        $this->basket = Basket::create($this->siteId);
        $this->item = $this->basket->createItem('catalog', $this->requestFieldsArray["one_click_order_id"]);
        $this->item->setFields(array(
            'QUANTITY' => 1,
            'CURRENCY' => $this->currencyCode,
            'LID' => $this->siteId,
            'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
        ));
    }

    private function createShipmentDelivery()
    {
        $shipmentCollection = $this->order->getShipmentCollection();
        $shipment = $shipmentCollection->createItem();
        $service = Delivery\Services\Manager::getById(Delivery\Services\EmptyDeliveryService::getEmptyDeliveryServiceId());
        $shipment->setFields(array(
            'DELIVERY_ID' => $service['ID'],
            'DELIVERY_NAME' => $service['NAME'],
        ));
        $shipmentItemCollection = $shipment->getShipmentItemCollection();
        $shipmentItem = $shipmentItemCollection->createItem($this->item);
        $shipmentItem->setQuantity($this->item->getQuantity());
    }

    private function createPayment()
    {
        $paymentCollection = $this->order->getPaymentCollection();
        $payment = $paymentCollection->createItem();
        $paySystemService = PaySystem\Manager::getObjectById(4);
        $payment->setFields(array(
            'PAY_SYSTEM_ID' => $paySystemService->getField("PAY_SYSTEM_ID"),
            'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
        ));
    }

    private function setProperties()
    {
        $propertyCollection = $this->order->getPropertyCollection();

        foreach ($propertyCollection as $property) {
            $p = $property->getProperty();
            if ($p["CODE"] === "SERNAME") {
                $property->setValue($this->requestFieldsArray["last_name"]);
            }
            if ($p["CODE"] === "NAME") {
                $property->setValue($this->requestFieldsArray["name"]);
            }
            if ($p["CODE"] === "EMAIL") {
                $property->setValue($this->requestFieldsArray["email"]);
            }
            if ($p["CODE"] === "PHONE_NUMBER") {
                $property->setValue($this->requestFieldsArray["phone"]);
            }
        }
    }

    private function makeOrder()
    {
        if ($this->requestFieldsArray["one_click_order_id"] > 0) {

            CSaleBasket::DeleteAll(CSaleBasket::GetBasketUserID());

            //create new order
            $this->createNewOrder();

            //create basket with one item
            $this->createBasket();
            $this->order->setBasket($this->basket);

            //shipment and delivery
            $this->createShipmentDelivery();

            //payment
            $this->createPayment();

            //set properties
            $this->setProperties();

            $this->order->doFinalAction(true);
            $result = $this->order->save();
            $orderId = $this->order->getId();
            if ($orderId > 0) {
                $this->sendNotification();
                echo json_encode(["status" => "success", "data" => self::SUCCESS_MESSAGE]);
                die();
            } else {
                array_push($this->errors, "Ошибка оформления заказа. Обратитесь в техподдержку");
                echo json_encode(["status" => "error", "data" => $this->errors]);
                die();
            }
        } else {
            array_push($this->errors, "Не передан ID товара. Обратитесь в техподдержку");
            echo json_encode(["status" => "error", "data" => $this->errors]);
            die();
        }
    }

    private function getOrderListString()
    {
        $arrItems = $this->basket->getListOfFormatText();
        $strItems = "<ul>";
        foreach ($arrItems as $item) {
            $strItems .= "<li>" . $item . "</li>";
        }
        $strItems .= "</ul>";
        return $strItems;
    }


    private function sendNotification()
    {
        $prot = $this->getProtocol();
        global $DB;
        $arEventFields = array(
            "NAME" => $this->requestFieldsArray["name"],
            "LAST_NAME" => $this->requestFieldsArray["last_name"],
            "PHONE" => $this->requestFieldsArray["phone"],
            "EMAIL" => $this->requestFieldsArray["email"],
            "LINK" => $prot . "" . $_SERVER["HTTP_HOST"] . "/bitrix/admin/sale_order_view.php?ID=" . $this->requestFieldsArray["one_click_order_id"] . "&lang=ru&filter=Y&set_filter=Y",
            "COMMENT" => !empty($this->requestFieldsArray["comment"]) ? $this->requestFieldsArray["comment"] : "отсутствует",
            "ORDER_ID" => $this->requestFieldsArray["one_click_order_id"],
            "ORDER_DATE" => date($DB->DateFormatToPHP(\CSite::GetDateFormat("SHORT")), time()),
            "ORDER_LIST" => $this->getOrderListString(),
            "PRICE" =>  CurrencyFormat($this->basket->getPrice(), $this->order->getCurrency())//$this->basket->getPrice()." ".$this->order->getCurrency()
        );

        CEvent::SendImmediate(self::EVENT_ID, "s1", $arEventFields);
    }

    private function getProtocol()
    {
        if ($_SERVER["HTTPS"]) {
            return "https://";
        }

        return "http://";
    }

    public function execute()
    {
        try {
            $this->checkRequestFields($this->requestFieldsArray);
            if (!empty($this->errors)) {
                echo json_encode(["status" => "error", "data" => $this->errors]);
            } else {
                $this->makeOrder();
            }
        } catch (SystemException $e) {
            ShowError($e->getMessage());
        }
    }
}









