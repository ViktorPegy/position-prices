<?php

interface PositionPricesSourceInterface
{
    public function getPricesGroupByPosition(): array;
}

class PositionPricesSource implements PositionPricesSourceInterface
{
    private $positions_prices;

    public function __construct(array $positions_prices)
    {
        $this->positions_prices = $positions_prices;
    }

    public function getPricesGroupByPosition(): array
    {
        static $grouped_prices;
        if ($grouped_prices === null) {
            $grouped_prices = [];

            foreach ($this->positions_prices as $price) {
                $grouped_prices[$price['position_id']][] = $price;
            }

        }

        return $grouped_prices;
    }
}

// ----

class PositionPriceDenormalizer
{
    private $source;

    public function __construct(PositionPricesSourceInterface $source)
    {
        $this->source = $source;
    }

    public function getDenormalizedPositionsPrices(): array
    {
        $result = [];

        foreach ($this->source->getPricesGroupByPosition() as $position_prices) {
            // ORDER BY delivery_date_from ASC, order_date_from ASC
            usort($position_prices, function ($a, $b) {
                if (($cmp = strcmp($a['delivery_date_from'], $b['delivery_date_from'])) === 0) {
                    return strcmp($a['order_date_from'], $b['order_date_from']);
                }
                return $cmp;
            });

            foreach ($position_prices as $key => $position_price) {
                $order_dates_with_price = [];
                foreach ($position_prices as $cmp_key => $cmp_position_price) {
                    // Рассматриваем только цены с меньшей или равной delivery_date_from
                    // Благодаря сортировке, такого условия достаточно
                    if ($cmp_key > $key) {
                        break;
                    }
                    
                    $cmp_order_date_from = $cmp_position_price['order_date_from'];
                    // Находим все цены с меньшей или равной order_date_from
                    if ($cmp_order_date_from <= $position_price['order_date_from']) { 
                        $order_dates_with_price[] = [
                            'date'  => $cmp_order_date_from,
                            'price' => $cmp_position_price['price'],
                        ];
                    }
                }

                // Диапазон даты доставки
                $delivery_date_from = $position_price['delivery_date_from'];
                $delivery_date_to = $position_prices[$key + 1]['delivery_date_from'] ?? null;
                if ($delivery_date_to) {
                    $delivery_date_to = date('Y-m-d', strtotime($delivery_date_to . ' - 1 day'));
                }

                // Сортируем даты заказа по возврастанию, для формирования диапазонов
                usort($order_dates_with_price, function ($a, $b) {
                    return strcmp($a['date'], $b['date']);
                });

                // Формируем диапазоны даты заказа, комбинируем с диапазоном даты доставки
                foreach ($order_dates_with_price as $k => $date_with_price) {
                    $order_date_from = $date_with_price['date'];
                    $order_date_to = $order_dates_with_price[$k + 1]['date'] ?? null;
                    if ($order_date_to) {
                        $order_date_to = date('Y-m-d', strtotime($order_date_to . ' - 1 day'));
                    }

                    $result[] = [
                        'position_id'        => $position_price['position_id'],
                        'order_date_from'    => $order_date_from,
                        'order_date_to'      => $order_date_to,
                        'delivery_date_from' => $delivery_date_from,
                        'delivery_date_to'   => $delivery_date_to,
                        'price'              => $date_with_price['price'],
                    ];
                }
            }
        }

        return $result;
    }
}

// ---- MAIN

$prices = [
    ['position_id' => 1, 'order_date_from' => '2019-02-01', 'delivery_date_from' => '2019-03-01', 'price' => 100],
    ['position_id' => 1, 'order_date_from' => '2019-02-10', 'delivery_date_from' => '2019-03-10', 'price' => 200],
    ['position_id' => 1, 'order_date_from' => '2019-02-20', 'delivery_date_from' => '2019-02-25', 'price' => 130],
];

$source = new PositionPricesSource($prices);
$denormalizer = new PositionPriceDenormalizer($source);
$denormalized_prices = $denormalizer->getDenormalizedPositionsPrices();

// ---- PRINT

function col($str) {
    return str_pad($str ?? 'NULL', 22, ' ', STR_PAD_LEFT);
}

echo implode(array_map('col', array_keys($denormalized_prices[0]))), "\n";
foreach ($denormalized_prices as $price) {
    echo implode("\t", array_map('col', $price)), "\n";
}

