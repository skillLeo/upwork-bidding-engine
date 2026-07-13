import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Next.js otherwise sizes its build worker pool to os.cpus().length, which
  // on shared hosting reports the *host's* full core count (64 here) rather
  // than what this account is actually allowed to spawn — CloudLinux's LVE
  // process cap then makes the build fail with EAGAIN. Force a single
  // worker so the build never tries to fork more than one child process.
  experimental: {
    cpus: 1,
  },
  // Hostinger's edge CDN (hcdn) caches pages using whatever Cache-Control
  // Next.js sends, and Next's default for a route with no dynamic
  // server-side data fetching is a very long s-maxage (seen: 1 year) —
  // fine for a marketing site, wrong for an authenticated dashboard where
  // every route's real content only exists client-side via SWR. Force
  // every response to skip both the browser and edge cache so a deploy is
  // never masked by a stale cached page again.
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [{ key: "Cache-Control", value: "no-store, must-revalidate" }],
      },
    ];
  },
};

export default nextConfig;
