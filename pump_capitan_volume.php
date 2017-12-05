<?php

include_once 'Client.php';
include_once 'key.php';
include_once 'Colors.php';

$client = new Client($key, $secret);

$sleep_seconds = 0.5;
$originalVolumes = array();
$colors = new Colors();
$cycle_count = 0;

while (true) {
    log_message("getMarketSummaries BEFORE");
    try {
        $result = $client->getMarketSummaries();
    } catch (Exception $e) {
        log_message($e->getMessage());
        throw $e;
    }
    log_message("getMarketSummaries OK");

    $maxPercentChange = 0;
    $maxMarketName = "";
    $maxAskPrice = 0;
    $volumeDiff = 0;

    foreach ($result as $marketSummary) {
        $marketName = $marketSummary->MarketName;
        if (!startsWith($marketName, 'BTC-')) continue;

        $Ask = $marketSummary->Ask;
        $Last = $marketSummary->Last;
        $BaseVolume = $marketSummary->BaseVolume;

        if ($Last == 0) continue;

        $originalVolume = $originalVolumes[$marketName];
        if (isset($originalVolume)) {
            $volumeChange = ($BaseVolume - $originalVolume) * 100 / $originalVolume; //in percents
            if ($volumeChange > $maxPercentChange) {
                $maxPercentChange = $volumeChange;
                $maxMarketName = $marketName;
                $maxAskPrice = $Ask;
                $volumeDiff = $BaseVolume - $originalVolume;
            }
        } else {
            $originalVolumes[$marketName] = $BaseVolume;
        }
    }

    if ($cycle_count == 0) {
        log_message("\n");
        log_message("Original volumes:");
        foreach ($originalVolumes as $market=>$volume) {
            log_message($market." (original volume: ".$originalVolumes[$market].")");
        }
    }

    $warning_limit = 5;  //in percents
    $success_limit = 15; //in percents
    $btc_volume = 4; //in BTC

    log_message(date("Y-m-d H:i:s"));
    $color = 'light_red';
    if ($maxPercentChange >= $warning_limit && $maxPercentChange < $success_limit) {
        $color = 'yellow';
    } else if ($maxPercentChange >= $success_limit) {
        $color = 'light_green';
    }
    log_message($maxPercentChange . "% " . $maxMarketName, true, $color);

    if ($maxPercentChange >= $success_limit && $volumeDiff >= $btc_volume) {
        pump($maxMarketName, $maxAskPrice);
    }

    log_message("\n");

    usleep($sleep_seconds * 1000000);

    $cycle_count++;
}

function log_message($message, $colored = false, $color = 'green')
{
    global $colors;
    if ($colored) {
        echo $colors->getColoredString($message, $color, null). "\n";
    } else {
        echo $message . "\n";
    }
    file_put_contents("pump_capitan_volume.txt", date("Y-m-d_H:i:s")." ".$message."\n", FILE_APPEND);
}

function pump($market, $askPrice) {
    global $client;

    $currency = "BTC";

    $sleep_seconds_after_buy = 1;
    $sleep_seconds_after_sell = 1;

    //balance in BTC
    $balance = 0.04; //TODO: This is example

    //buy_factor <= sell_factor
    $buy_factor = 1.5;
    $sell_factor = 1.5;

    //BUY

    echo "***** BUY *****\n\n";

    $rate = $askPrice * $buy_factor;
//$original_quantity = (int)($balance / $rate);
    $original_quantity = (0.99 * $balance) / $rate;
    echo "Rate:\n";
    echo $rate."\n";
    echo "Original Quantity:\n";
    echo $original_quantity;
    echo "\n\n";

    log_message("\n***** BUY ***** rate: ".$rate." ".$currency.", original_quantity: ".$original_quantity);
    $result = $client->buyLimit($market, $original_quantity, $rate);
    print_r($result);

    $uuid_field = 'uuid';
    $uuid = $result->$uuid_field;
    echo "UUID:\n";
    echo $uuid;
    log_message("***** BUY PLACED ***** uuid: ".$uuid);

    echo "\n\n";

    do {
        usleep($sleep_seconds_after_buy * 1000000);

        $quantity = getOrderInfo("[BUY] ", $uuid, $original_quantity, $currency);
    } while ($quantity < (0.99 * $original_quantity));

    //SELL

    echo "***** SELL *****\n\n";

    $rate = $askPrice * $sell_factor;
    echo "Rate:\n";
    echo $rate."\n";
    echo "Quantity:\n";
    echo $quantity;
    echo "\n\n";

    log_message("\n***** SELL ***** rate: ".$rate." ".$currency.", quantity: ".$quantity);
    $result = $client->sellLimit($market, $quantity, $rate);
    print_r($result);

    $uuid_field = 'uuid';
    $uuid = $result->$uuid_field;
    echo "UUID:\n";
    echo $uuid;
    log_message("***** SELL PLACED ***** uuid: ".$uuid);

    while (true) {
        usleep($sleep_seconds_after_sell * 1000000);

        getOrderInfo("[SELL] ", $uuid, $original_quantity, $currency);
    }

    echo "\n\n";
}

function getOrderInfo($type, $uuid, $original_quantity, $currency) {
    global $client;

    $result = $client->getOrder($uuid);
    print_r($result);

    $quantity_field = 'Quantity';
    $quantity = $result->$quantity_field;
    $quantity_remaining_field = 'QuantityRemaining';
    $quantity_remaining = $result->$quantity_remaining_field;
    $price_field = 'PricePerUnit';
    $price = $result->$price_field;

    echo $type."Quantity:\n";
    echo $quantity."\n";
    echo $type."QuantityRemaining:\n";
    echo $quantity_remaining."\n";
    echo $type."Price:\n";
    echo $price."\n\n";
    log_message($type."Order info quantity: ".$quantity.", quantity_remaining: ".$quantity_remaining.", price: ".$price." ".$currency);
    log_message($type."Quantity percent bought: ". ((($quantity - $quantity_remaining) / $original_quantity) * 100)."%");

    return $quantity;
}

function startsWith($haystack, $needle) {
    // search backwards starting from haystack length characters from the end
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
}
