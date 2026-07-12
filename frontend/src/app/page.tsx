"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuthStore } from "@/stores/auth-store";

export default function RootPage() {
  const router = useRouter();
  const hasHydrated = useAuthStore((s) => s.hasHydrated);
  const token = useAuthStore((s) => s.token);

  useEffect(() => {
    if (!hasHydrated) return;
    router.replace(token ? "/leads" : "/login");
  }, [hasHydrated, token, router]);

  return (
    <div className="flex min-h-screen flex-1 items-center justify-center bg-bg">
      <div className="h-8 w-8 animate-spin rounded-full border-2 border-border-strong border-t-primary" />
    </div>
  );
}
