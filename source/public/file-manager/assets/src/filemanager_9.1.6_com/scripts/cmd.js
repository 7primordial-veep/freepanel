const workDir = process.cwd();

var cmd = require("commander");
cmd.version("0.1");

cmd
	.command("server")
	.description("run development server")
	.option("-p, --port [port]", "production build")
	.option("-w, --webix [port]", "path to webix codebase")
	.action(function(cmd) {
		require("./build").server(workDir, cmd);
	});

cmd
	.command("build")
	.description("build static files")
	.option("-c, --compress", "build compressed version")
	.option("-p, --preserve", "preserve result of previous build")
	.option("-b, --banner [text]", "banner text for js/css files")
	.action(function(cmd) {
		require("./build").build(workDir, cmd);
	});

cmd
	.command("dist [type]")
	.description(
		"build component's package, possible types: com, edu, trial, site"
	)
	.option("-t, --target [target]", "Target folder")
	.option("-z, --zip", "zip package")
	.option("-b, --build", "build package")
	.option("-s, --server [url]", "url of backend")
	.action(function(type, cmd) {
		require("./package").build(workDir, type || "com", cmd);
	});

cmd.parse(process.argv);
