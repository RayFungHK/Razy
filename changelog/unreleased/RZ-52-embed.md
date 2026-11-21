# RZ-52: embedding API (boot a distributor from your own script)

## Added
- `Razy\Embed::boot(string $root, string $dist): Distributor`: the documented entry for a process that is not the web front controller or the `Razy.phar` command line. It checks the root and `sites/<dist>/dist.php` (a bad call throws `ConfigurationException` before anything is defined), defines `SYSTEM_ROOT` and the path constants, loads the bootstrap `main.php` loads, and returns an initialised Distributor. Load it by `phar://<file>/library/Razy/Embed.php` or composer's autoloader; requiring `Razy.phar` itself runs the CLI dispatcher. A second call with the same root is allowed; a different root in one process throws. Manual: `manual/06-packages-and-deployment.md` §6.
- `Application::getDistributor(string $code): ?Distributor`: the initialised Distributor for a code mounted on the host `host()` matched, or null. `hasDistributor()` takes the same key.

## Fixed
- `php Razy.phar bridge` works. It called `Application::getDistributor()`, which did not exist (`{"ok":false,"error":"Call to undefined method Razy\\Application::getDistributor()"}`).
- A Distributor built without a Domain (`runapp`, `Embed::boot()`, the compile and package tools) registers its own module library autoloader in `initialize()`, once per Distributor. A module command that used a class from the module's `library/` folder failed with `Class "..." not found` on that path; only `Application::host()` registered an autoloader.
- `php Razy.phar build` run from a folder that has no `sites/` and is not the phar's folder installed Razy next to the phar. It now prints an error, exits 1 and writes nothing.

## Compatibility
- `build` from a folder without `sites/` (or `standalone/`) and not the phar's own folder used to install into the phar's folder; it now exits 1. Running `build` in the phar's own folder, or in a folder that has `sites/`, is unchanged.
- A Distributor with no Domain now registers an autoloader in `initialize()`. Code that registered `spl_autoload_register(fn ($c) => $dist->autoload($c))` itself for such a Distributor (`Testing\Client`, `Package\PackageRunner`) keeps working: the second autoloader is only asked for a class the first did not load. The registration holds the Distributor weakly and is removed when the Distributor is destroyed. Distributors built by `Domain` (the web and worker path) add none.
- No existing method changed; `Distributor::autoload()` and the Application autoloader are as before.
