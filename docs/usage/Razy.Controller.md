# Razy\Controller

## Summary
- Base class for module controllers.
- Defines lifecycle hooks, routing entry, API access, and template helpers.

## Construction
- Anonymous controller class is loaded from module controller file.

## Key methods
- Lifecycle: `__onInit()`, `__onLoad()`, `__onRequire()`, `__onReady()`, `__onRouted()`, `__onEntry()`, `__onDispose()`.
- Error handling: `__onError()`.
- API access: `__onAPICall()`, `api($moduleCode)`.
- Routing helpers: `getRoutedInfo()`, `goto($path)`.
- Template helpers: `loadTemplate()`, `getTemplate()`, `view()`, `getTemplateFilePath()`.
- Data helpers: `getDataPath()`, `getDataPathURL()`.
- Plugins: `registerPluginLoader()`.
- Events: `trigger()`, `handshake()`.

## Usage notes
- Override lifecycle hooks to configure and guard module behavior.
- `__call()` loads controller closures by convention.
