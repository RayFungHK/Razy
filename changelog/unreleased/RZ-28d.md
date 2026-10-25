# RZ-28d: UI fixtures for `.rzx` components

Follow-up to RZ-28c and RZ-29. Additive.

## Added
- `Razy\View\Fixture::component('Kit.List')` creates a fixture for a `.rzx` component. Its states are
  props. The fixture's own folder is the component's namespace directory.
  - `namespaces(['App' => '../app'])` adds the other component folders the component uses.
  - `vocabulary('vocabulary.json')` compiles against a vocabulary.
  - `rzxFunction('t', $fn)` registers app functions.
  - `module('kit')` (or `module('kit', 'path/to/webassets')`, default `../webassets`) gives the
    component's `asset()` calls a module: for that render, Assets resolve the alias to that folder,
    and the previous Assets are restored afterwards.
  - `fill('slot', '<x-button …>')` gives a slot fixed markup. The markup is fixture code, printed
    as written.
- `FixtureRenderer::render()` compiles and renders a component fixture with the same compiler, Razy
  functions and anchors as a site:
  - pushes reach the frame's anchors, with the nonce;
  - props are checked at the boundary;
  - compiled files go to a temp cache per fixture file.
- `FixtureRenderer::describe()` (and `ui list --json`) gains `component` (the name, or null for a
  `.tpl` fixture).
