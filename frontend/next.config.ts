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
};

export default nextConfig;
