/**
 * Custom Node entry point for Hostinger's "Setup Node.js App" (Passenger).
 * Passenger expects a script that boots an HTTP server listening on
 * process.env.PORT — `next start` alone isn't invocable that way, so this
 * wraps Next's programmatic API instead. Not used for local dev (`npm run
 * dev`/`npm run start` still work normally there).
 */
const { createServer } = require("http");
const next = require("next");

const port = process.env.PORT || 3000;
const app = next({ dev: false });
const handle = app.getRequestHandler();

app.prepare().then(() => {
  createServer((req, res) => handle(req, res)).listen(port, () => {
    console.log(`SkillLeo frontend ready on port ${port}`);
  });
});
