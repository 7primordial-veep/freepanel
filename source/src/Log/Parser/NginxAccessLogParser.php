<?php

namespace App\Log\Parser;

class NginxAccessLogParser
{
    const GROK_PATTERN = "%{iporhost:network_client_ip} - %{username:remote_user} \\[%{httpdate:date}\\] \"(?:%{word:http_method} %{notspace:url}(?: HTTP/%{number:http_version}))\" %{number:http_status_code} (?:%{number:network_bytes}|-) \\\"%{data:http_referer}\\\" \\\"%{data:http_user_agent}\\\"";
    private ?string $logFileContent = null;
    private array $parsedLogLines = [];
    private ?GrokParser $grokParser = null;
    private $timezone = "Europe/Berlin";
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
    private function parseLogLine($logLine) : array
    {
        $parsedLogLine = [];
        $grokParser = $this->getGrokParser();
        $parseResult = $grokParser->parse($logLine);
        if (false !== $parseResult && count($parseResult)) {
            $networkClientIp = $parseResult["network_client_ip"] ?? '';
            $remoteUser = $parseResult["remote_user"] ?? '';
            $date = $parseResult["date"] ?? '';
            if (false === empty($date)) {
                $timezone = $this->getTimezone();
                $dateTime = new \DateTime($date, new \DateTimeZone("UTC"));
                $dateTime->setTimezone(new \DateTimeZone($timezone));
            }
            $httpMethod = $parseResult["http_method"] ?? '';
            $url = $parseResult["url"] ?? '';
            $httpVersion = $parseResult["http_version"] ?? '';
            $httpStatusCode = $parseResult["http_status_code"] ?? '';
            $networkBytes = $parseResult["network_bytes"] ?? '';
            $httpReferer = $parseResult["http_referer"] ?? '';
            $httpUserAgent = $parseResult["http_user_agent"] ?? '';
            $parsedLogLine = ["networkClientIp" => $networkClientIp, "remoteUser" => $remoteUser, "date" => $dateTime->format("Y-m-d H:i:s"), "dateRaw" => $date, "httpMethod" => $httpMethod, "url" => $url, "httpVersion" => $httpVersion, "httpStatusCode" => $httpStatusCode, "networkBytes" => $networkBytes, "httpReferer" => $httpReferer, "httpUserAgent" => $httpUserAgent];
        }
        return $parsedLogLine;
    }
    public function getParsedLogLines() : array
    {
        return $this->parsedLogLines;
    }
    public function setTimezone(string $timezone) : void
    {
        $this->timezone = $timezone;
    }
    public function getTimezone() : ?string
    {
        return $this->timezone;
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