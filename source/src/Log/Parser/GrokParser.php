<?php

namespace App\Log\Parser;

class GrokParser
{
    protected $patternRegex = "/(?!<\\\\)%\\{" . "(?<name>" . "(?<pattern>[A-z0-9]+)" . "(?::(?<subname>[A-z0-9_:]+))?" . ")" . "(?:=" . "(?<definition>" . "(?:" . "(?P<curly2>\\{(?:(?>[^{}]+|(?>\\\\[{}])+)|(?P>curly2))*\\})+" . "|" . "(?:[^{}]+|\\\\[{}])+" . ")+" . ")" . ")?" . "\\s*(?<predicate>" . "(?:" . "(?P<curly>\\{(?:(?>[^{}]+|(?>\\\\[{}])+)|(?P>curly))*\\})" . "|" . "(?:[^{}]+|\\\\[{}])+" . ")+" . ")?" . "\\}/";
    protected $pattern;
    protected $matchCount = 0;
    protected $patterns = ["USERNAME" => "[a-zA-Z0-9._-]+", "USER" => "%{USERNAME}", "INT" => "(?:[+-]?(?:[0-9]+))", "BASE10NUM" => "(?<![0-9.+-])(?>[+-]?(?:(?:[0-9]+(?:\\.[0-9]+)?)|(?:\\.[0-9]+)))", "NUMBER" => "(?:%{BASE10NUM})", "BASE16NUM" => "(?<![0-9A-Fa-f])(?:[+-]?(?:0x)?(?:[0-9A-Fa-f]+))", "BASE16FLOAT" => "\\b(?<![0-9A-Fa-f.])(?:[+-]?(?:0x)?(?:(?:[0-9A-Fa-f]+(?:\\.[0-9A-Fa-f]*)?)|(?:\\.[0-9A-Fa-f]+)))\\b", "POSINT" => "\\b(?:[1-9][0-9]*)\\b", "NONNEGINT" => "\\b(?:[0-9]+)\\b", "WORD" => "\\b\\w+\\b", "NOTSPACE" => "\\S+", "SPACE" => "\\s*", "DATA" => ".*?", "GREEDYDATA" => ".*", "GREEDYDATA_FULL" => "(.|\\r|\\n)*", "GREEDYDATA_END_OF_MESSAGE" => "(?s)(.*\$)", "QUOTEDSTRING" => "(?>(?<!\\)(?>\"(?>\\.|[^\\\"]+)+\"|\"\"|(?>'(?>\\.|[^\\']+)+')|''|(?>`(?>\\.|[^\\`]+)+`)|``))", "UUID" => "[A-Fa-f0-9]{8}-(?:[A-Fa-f0-9]{4}-){3}[A-Fa-f0-9]{12}", "MAC" => "(?:%{CISCOMAC}|%{WINDOWSMAC}|%{COMMONMAC})", "CISCOMAC" => "(?:(?:[A-Fa-f0-9]{4}\\.){2}[A-Fa-f0-9]{4})", "WINDOWSMAC" => "(?:(?:[A-Fa-f0-9]{2}-){5}[A-Fa-f0-9]{2})", "COMMONMAC" => "(?:(?:[A-Fa-f0-9]{2}:){5}[A-Fa-f0-9]{2})", "IPV6" => "((([0-9A-Fa-f]{1,4}:){7}([0-9A-Fa-f]{1,4}|:))|(([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}|((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){5}(((:[0-9A-Fa-f]{1,4}){1,2})|:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){4}(((:[0-9A-Fa-f]{1,4}){1,3})|((:[0-9A-Fa-f]{1,4})?:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){3}(((:[0-9A-Fa-f]{1,4}){1,4})|((:[0-9A-Fa-f]{1,4}){0,2}:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){2}(((:[0-9A-Fa-f]{1,4}){1,5})|((:[0-9A-Fa-f]{1,4}){0,3}:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){1}(((:[0-9A-Fa-f]{1,4}){1,6})|((:[0-9A-Fa-f]{1,4}){0,4}:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}))|:))|(:(((:[0-9A-Fa-f]{1,4}){1,7})|((:[0-9A-Fa-f]{1,4}){0,5}:((25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}))|:)))(%.+)?", "IPV4" => "(?<![0-9])(?:(?:25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{1,2})[.](?:25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{1,2})[.](?:25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{1,2})[.](?:25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{1,2}))(?![0-9])", "IP" => "(?:%{IPV6}|%{IPV4})", "HOSTNAME" => "\\b(?:[0-9A-Za-z][0-9A-Za-z-]{0,62})(?:\\.(?:[0-9A-Za-z][0-9A-Za-z-]{0,62}))*(\\.?|\\b)", "HOST" => "%{HOSTNAME}", "IPORHOST" => "(?:%{HOSTNAME}|%{IP})", "HOSTPORT" => "(?:%{IPORHOST=~/\\./}:%{POSINT})", "PATH" => "(?:%{UNIXPATH}|%{WINPATH})", "UNIXPATH" => "(?>/(?>[\\w_%!\$@:.,-]+|\\.)*)+", "LINUXTTY" => "(?:/dev/pts/%{NONNEGINT})", "TTY" => "(?:/dev/(pts|tty([pq])?)(\\w+)?/?(?:[0-9]+))", "WINPATH" => "(?>[A-Za-z]+:|\\)(?:\\[^\\?*]*)+", "URIPROTO" => "[A-Za-z]+(\\+[A-Za-z+]+)?", "URIHOST" => "%{IPORHOST}(?::%{POSINT:port})?", "URIPATH" => "(?:/[A-Za-z0-9\$.+!*'(){},~:;=@#%_\\-]*)+", "URIPARAM" => "\\?[A-Za-z0-9\$.+!*'|(){},~@#%&/=:;_?\\-\\[\\]]*", "URIPATHPARAM" => "%{URIPATH}(?:%{URIPARAM})?", "URI" => "%{URIPROTO}://(?:%{USER}(?::[^@]*)?@)?(?:%{URIHOST})?(?:%{URIPATHPARAM})?", "MONTH" => "\\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\\b", "MONTHNUM" => "(?:0?[1-9]|1[0-2])", "MONTHDAY" => "(?:(?:0[1-9])|(?:[12][0-9])|(?:3[01])|[1-9])", "DAY" => "(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)", "YEAR" => "(?>\\d\\d){1,2}", "HOUR" => "(?:2[0123]|[01]?[0-9])", "MINUTE" => "(?:[0-5][0-9])", "SECOND" => "(?:(?:[0-5][0-9]|60)(?:[:.,][0-9]+)?)", "TIME" => "(?!<[0-9])%{HOUR}:%{MINUTE}(?::%{SECOND})(?![0-9])", "DATE_US" => "%{MONTHNUM}[/-]%{MONTHDAY}[/-]%{YEAR}", "DATE_EU" => "%{MONTHDAY}[./-]%{MONTHNUM}[./-]%{YEAR}", "ISO8601_TIMEZONE" => "(?:Z|[+-]%{HOUR}(?::?%{MINUTE}))", "ISO8601_SECOND" => "(?:%{SECOND}|60)", "TIMESTAMP_ISO8601" => "%{YEAR}-%{MONTHNUM}-%{MONTHDAY}[T ]%{HOUR}:?%{MINUTE}(?::?%{SECOND})?%{ISO8601_TIMEZONE}?", "DATE" => "%{DATE_US}|%{DATE_EU}", "DATESTAMP" => "%{DATE}[- ]%{TIME}", "TZ" => "(?:[PMCE][SD]T|UTC)", "DATESTAMP_RFC822" => "%{DAY} %{MONTH} %{MONTHDAY} %{YEAR} %{TIME} %{TZ}", "DATESTAMP_OTHER" => "%{DAY} %{MONTH} %{MONTHDAY} %{TIME} %{TZ} %{YEAR}", "SYSLOGTIMESTAMP" => "%{MONTH} +%{MONTHDAY} %{TIME}", "PROG" => "(?:[\\w._/%-]+)", "SYSLOGPROG" => "%{PROG:program}(?:\\[%{POSINT:pid}\\])?", "SYSLOGHOST" => "%{IPORHOST}", "SYSLOGFACILITY" => "<%{NONNEGINT:facility}.%{NONNEGINT:priority}>", "HTTPDATE" => "%{MONTHDAY}/%{MONTH}/%{YEAR}:%{TIME} %{INT}", "QS" => "%{QUOTEDSTRING}", "SYSLOGBASE" => "%{SYSLOGTIMESTAMP:timestamp} (?:%{SYSLOGFACILITY} )?%{SYSLOGHOST:logsource} %{SYSLOGPROG}:", "COMMONAPACHELOG" => "%{IPORHOST:clientip} %{USER:ident} %{USER:auth} \\[%{HTTPDATE:timestamp}\\] \"(?:%{WORD:verb} %{NOTSPACE:request}(?: HTTP/%{NUMBER:httpversion})?|%{DATA:rawrequest})\" %{NUMBER:response} (?:%{NUMBER:bytes}|-)", "COMBINEDAPACHELOG" => "%{COMMONAPACHELOG} %{QS:referrer} %{QS:agent}", "LOGLEVEL" => "([A-a]lert|ALERT|[T|t]race|TRACE|[D|d]ebug|DEBUG|[N|n]otice|NOTICE|[I|i]nfo|INFO|[W|w]arn?(?:ing)?|WARN?(?:ING)?|[E|e]rr?(?:or)?|ERR?(?:OR)?|[C|c]rit?(?:ical)?|CRIT?(?:ICAL)?|[F|f]atal|FATAL|[S|s]evere|SEVERE|EMERG(?:ENCY)?|[Ee]merg(?:ency)?)", "NGINX_ERROR_LOG_DATE" => "%{YEAR}[./]%{MONTHNUM}[./]%{MONTHDAY} %{TIME}"];
    protected $fieldMap = [];
    public function setPattern($pattern)
    {
        $this->pattern = $pattern;
    }
    public function getPattern()
    {
        return $this->pattern;
    }
    public function addPattern($name, $pattern)
    {
        $this->patterns[$name] = $pattern;
    }
    public function addPatterns(array $patterns)
    {
        $this->patterns = array_merge($this->patterns, $patterns);
    }
    protected function reset()
    {
        $this->matchCount = 0;
        $this->fieldMap = [];
    }
    public function resolve($pattern)
    {
        if (preg_match_all($this->patternRegex, $pattern, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $subPattern = $this->resolve($this->patterns[strtoupper($match["pattern"])]);
                if (isset($match["subname"]) && !empty($match["subname"])) {
                    $this->fieldMap[++$this->matchCount] = $match["subname"];
                    $subPattern = "(?<" . $match["subname"] . ">" . $subPattern . ")";
                }
                $pattern = str_replace($match[0], $subPattern, $pattern, $replaced);
            }
        }
        return $pattern;
    }
    public function parse($content)
    {
        $results = [];
        $pattern = $this->getPattern();
        $this->reset();
        $pattern = "/" . str_replace("/", "\\/", $this->resolve($pattern)) . "/" . '';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            if (count($matches) > 0 && isset($matches[0]) && is_array($matches[0])) {
                foreach ($this->fieldMap as $pos => $key) {
                    if (!isset($matches[0][$key])) {
                        continue;
                    }
                    $value = $matches[0][$key];
                    $results[$key] = $value;
                }
            }
        }
        return !empty($results) ? $results : false;
    }
}