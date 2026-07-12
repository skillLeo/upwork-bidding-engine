"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Client-side gate only — the API enforces the real boundary (role:admin
 * middleware, 401/403 on every protected route). This just avoids flashing
 * admin-only UI at a bidder before redirecting them away.
 */
export function AuthGuard({
  children,
  adminOnly = false,
}: {
  children: React.ReactNode;
  adminOnly?: boolean;
}) {
  const router = useRouter();
  const hasHydrated = useAuthStore((s) => s.hasHydrated);
  const token = useAuthStore((s) => s.token);
  const user = useAuthStore((s) => s.user);

  const blocked = !hasHydrated || !token || (adminOnly && user?.role !== "admin");

  React.useEffect(() => {
    if (!hasHydrated) return;
    if (!token) {
      router.replace("/login");
    } else if (adminOnly && user?.role !== "admin") {
      router.replace("/leads");
    }
  }, [hasHydrated, token, user, adminOnly, router]);

  if (blocked) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-border-strong border-t-primary" />
      </div>
    );
  }

  return <>{children}</>;
}
