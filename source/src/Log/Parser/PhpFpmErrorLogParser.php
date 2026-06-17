<?php

namespace App\Log\Parser;

class PhpFpmErrorLogParser
{
    const GROK_PATTERN = "\\[%{DATA:php_fpm_date}\\] %{DATA:error_type}\\: %{GREEDYDATA:error_message}(\\nStack trace:\\n%{GREEDYDATA_FULL:stack_trace}\\n)?";
    private ?string $logFileContent = null;
    private array $parsedLogLines = [];
    private ?GrokParser $grokParser = null;
    public function __construct(string $logfileContent)
    {
        $this->logFileContent = $logfileContent;
    }
    public function parse() : array
    {
        $explodedLogLines = explode(PHP_EOL, $this->logFileContent);
        $numberOfLogLines = count($explodedLogLines);
        $logLines = [];
        $logLineData = [];
        $i = 0;
        while ($i < $numberOfLogLines) {
            $logLine = $explodedLogLines[$i] ?? '';
            $nextLogLine = $explodedLogLines[$i + 1] ?? '';
            $logLineData[] = $logLine;
            if ("[" == substr($nextLogLine, 0, 1) || $i + 1 == $numberOfLogLines) {
                $logLines[] = implode(PHP_EOL, $logLineData);
                $logLineData = [];
            }
            $i++;
        }
        if (count($logLines)) {
            foreach ($logLines as $logLine) {
                $parsedLogLine = $this->parseLogLine($logLine);
                if (!(false === empty($parsedLogLine))) {
                    continue;
                }
                $this->parsedLogLines[] = $parsedLogLine;
            }
        }
        return $this->parsedLogLines;
    }
    private function parseLogLine(string $logLine) : array
    {
        $parsedLogLine = [];
        $grokParser = $this->getGrokParser();
        $parseResult = $grokParser->parse($logLine);
        if (false !== $parseResult && count($parseResult)) {
            $date = $parseResult["php_fpm_date"] ?? '';
            $errorType = $parseResult["error_type"] ?? '';
            $errorMessage = $parseResult["error_message"] ?? '';
            $parsedLogLine = ["date" => $date, "errorType" => $errorType, "errorMessage" => $errorMessage, "logLine" => $logLine];
        }
        return $parsedLogLine;
    }
    public function getParsedLogLines() : array
    {
        return $this->parsedLogLines;
    }
    private function getGrokParser()
    {
        if (true === is_null($this->grokParser)) {
            $this->grokParser = new GrokParser();
            $this->grokParser->setPattern(self::GROK_PATTERN);
        }
        return $this->grokParser;
    }
}