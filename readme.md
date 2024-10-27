# **Razy v0.5 - The PHP framework for manageable development environment**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## What is the roadmap for v0.5 to become v1.0?

1. ✏️Enable downloading and installing modules from GitHub repositories via CLI
2. ✅Enhance the application workflow to reduce coding requirements
3. ✏️Cache system
4. ✏️Thread system
5. ✏️Revamp `Action` and `Validate` classes to streamline coding
6. ✏️Cross-distributor communication internally
7. ✏️More build-in classes and functions

## **What is new in v0.5?**

### **Simplified Flow**

In Razy v0.5, the module loading and routing stages have been revamped. The operation of each stage in module loading is now clearer and reduces the number of handshakes needed to ensure required modules are loaded successfully. Additionally, it will unload a module if one of its required modules fails to load.

***New module load and route flow***

__onInit(Agent $agent)

> Trigger when the module has been scanned and loaded in
>

Return false to mark the module as unloaded. This is used to set up the module's route, API, script, async callback, and event listener.

---

__onLoad(Agent $agent)

> Trigger when all modules have loaded and put into the queue
>

Based on loading order to preload module, return false to remove from the loading queue.

---

__onRequire()

> Trigger before any action, routing or script execute
>

Used to ensure the module has completed initialization, or return false to prevent routing or execution.

---

__onReady()

> Trigger after async callbacks is executed
>

Await callbacks (`Agent::await()`) are very useful for accessing the API before any routing or execution occurs. They ensure that required modules have already loaded into the system. All await callbacks execute between the __onRequire and __onReady stages, guaranteeing that API access in __onReady is completely safe.

---

__onScriptReady() / __onRouted

> Trigger when the URL query has matched
>

Typically used to perform pre-checks on the module before other modules are routed or executed.

---

__onEntry()

> Trigger when it has been routed successfully
>

At this stage, developers can preload parameters before any routed method is executed. This is typically used in conjunction with __onRouted() to handle redirecting.

---

Based on real-world development experience, Razy v0.5 has reduced the amount of code needed for handling module load status confirmation and preloading by 30%.

### Guaranteed Module Loading

Module loading order is now based on dependencies, preventing modules from being unable to access required modules during the loading stage. In previous versions, the module loading stage was confusing; developers often spent time and effort identifying whether a module was already loaded and determining the loading priority. To address this, I've added a handshake logic that allows modules to signal each other, ensuring they're ready for API access.

In Razy v0.5, I've introduced a new stage after `__onLoad` that allows modules to determine their status. This addition has reduced handshake times and minimized the code needed to preload certain functions, resulting in fewer conflicts.

### **Module config file required, new namespace rule**

Now developers need to create a `module.php` file in the module folder, or the module won't load. This file contains `module_code`, `author`, and `description` parameters, which are used for module loading and previewing. Additionally, the module code supports deeper levels for namespace paths and automatically requires the parent namespace's module.

For example, when the module code is named `rayfungpi/razit/user`, the `rayfungpi/razit` module is automatically required. This parent module needs to be loaded and is added to the require list when the system scans the module. This feature is particularly useful for bundle packages.

### Revamp Scope

Applications now run as instances, providing a more reliable workflow. Creating an Application object no longer automatically matches the domain, allowing for more flexible operations outside of running the application. When Razy is launched, an Application object must be created to start URL query matching or configure the application.

Normally, when routing starts and scripts run, the Application is locked to prevent developers from accessing the Application's core configuration functions.

### Distributor code is now standardized

It now supports using `-` in the code, and the distributor folder name must match the distributor code. Previous versions often caused confusion between the folder name and the distributor code, frequently leading to failed distributor setups.

## Any documentation for Razy?

I dislike writing documentation! However, it's essential. Before releasing any formal documentation, I plan to record some development cases to demonstrate how Razy works. I hope someone will be willing to help with documentation after watching my videos.

## Why Am I Dedicating Time to Developing Razy?

When I started my freelance career in system development, managing several projects simultaneously wasn't a problem—perhaps because I didn't have many jobs at the time ;). Following MVC made my development easier as a full-stack developer. However, after accumulating experience and revamping systems, I found myself struggling to update functions or overhaul subsystems. I needed to backport updates to older systems and access different servers to update them, which became painful. Any changes posed risks and took time, and I had to be cautious about making too many changes that I wanted to reuse in other projects.

Midway through my career, I realized I needed to develop my own framework instead of using open-source options. This decision stemmed from my desire to continuously improve my development skills and create a fast development environment for future projects. Razy is my biggest project (along with Void0.js, which I use to replace jQuery) and it's really helpful for managing different sites in a single place.

Additionally, Razy offers excellent code management and an improved development lifecycle. I can revert to older committed packages when something goes wrong or maintain a stable committed package for projects not yet ready for upgrades. If a client has custom functions in a module, I can easily clone the current version as a new package and begin development without concerns.

In 2024, despite the abundance of open-source options, I continue working on Razy because it embodies my values and showcases my experience. In today's business climate, developers using open-source frameworks are often seen as interchangeable—employers may choose based on cost rather than skill. Sadly, many developers in the current generation lack a deep understanding of logic and performance optimization, which I view as critical weaknesses.

My message to all developers is this: focus on enhancing your logical thinking, understanding diverse industry workflows, and mastering native programming language skills throughout your career. These fundamentals will enable you to seamlessly transition between programming languages, debug effectively in any framework, and streamline your work process to the point where it feels effortless.