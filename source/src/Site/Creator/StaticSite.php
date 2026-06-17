<?php

namespace App\Site\Creator;

use App\Site\Creator as SiteCreator;
use App\Site\Nginx\Vhost\StaticTemplate;
use App\System\Command\WriteFileCommand;
class StaticSite extends SiteCreator
{
    private const INDEX_TEMPLATE = "<html>\n<head>\n  <title>Hello World :-)</title>  \n</head>  \n<body>\n  Hello World :-)\n</body>  \n</html>";
    public function createIndexHtml() : void
    {
        $rootDirectory = $this->getRootDirectory();
        $indexHtmlFile = sprintf("%s/index.html", rtrim($rootDirectory, "/"));
        $writeIndexHtmlFileCommand = new WriteFileCommand();
        $writeIndexHtmlFileCommand->setFile($indexHtmlFile);
        $writeIndexHtmlFileCommand->setContent(self::INDEX_TEMPLATE);
        $this->commandExecutor->execute($writeIndexHtmlFileCommand);
    }
}