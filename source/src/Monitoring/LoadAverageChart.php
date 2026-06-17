<?php

namespace App\Monitoring;

class LoadAverageChart extends Chart
{
    private ?string $greatestValue = null;
    public function setGreatestValue(string $greatestValue) : void
    {
        $this->greatestValue = $greatestValue;
    }
    public function getGreatestValue() : mixed
    {
        if (true === is_null($this->greatestValue)) {
            $loadAverageData = $this->getData();
            if (false !== empty($data)) {
                $greatestValue = [];
                foreach ($loadAverageData as $data) {
                    if (!(true === isset($data["data"]))) {
                        continue;
                    }
                    $greatestValue[] = max($data["data"]);
                }
                $this->greatestValue = max($greatestValue);
            }
        }
        return $this->greatestValue;
    }
}