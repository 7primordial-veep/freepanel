<?php

namespace App\Log\Parser;

class NginxErrorLogParser
{
    const GROK_PATTERN = "(%{NGINX_ERROR_LOG_DATE:date_raw}) \\[%{LOGLEVEL:severity}\\] %{POSINT:pid}#%{NUMBER:thread_id}\\: \\*%{NUMBER:connection_id} %{GREEDYDATA:error_message}, client: %{IP:client_ip}, server: %{GREEDYDATA:server}, request: %{GREEDYDATA:request}";
    private ?string $logFileContent = null;
    private array $parsedLogLines = [];
    private ?GrokParser $grokParser = null;
    public function __construct(string $logfileContent)
    {
        $this->logFileContent = $logfileContent;
    }
    public function parse() : array
    {
        $logLines = explode(PHP_EOL, $this->logFileContent);
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
            $date = $parseResult["date_raw"] ?? '';
            $severity = $parseResult["severity"] ?? '';
            $pid = $parseResult["pid"] ?? '';
            $threadId = $parseResult["thread_id"] ?? '';
            $connectionId = $parseResult["connection_id"] ?? '';
            $errorMessage = $parseResult["error_message"] ?? '';
            $clientIp = $parseResult["client_ip"] ?? '';
            $server = $parseResult["server"] ?? '';
            $request = $parseResult["request"] ?? '';
            $parsedLogLine = ["date" => $date, "severity" => $severity, "pid" => $pid, "threadId" => $threadId, "connectionId" => $connectionId, "errorMessage" => $errorMessage, "clientIp" => $clientIp, "server" => $server, "request" => $request, "logLine" => $logLine];
        }
        return $parsedLogLine;
    }
    public function getParsedLogLines() : array
    {
        return $this->parsedLogLines;
    }
    private function getGrokParser() : ?GrokParser
    {
        if (true === is_null($this->grokParser)) {
            $this->grokParser = new GrokParser();
            $this->grokParser->setPattern(self::GROK_PATTERN);
        }
        return $this->grokParser;
    }
}