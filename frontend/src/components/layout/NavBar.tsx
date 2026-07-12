"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { BarChart3, ChevronDown, LayoutGrid, LogOut, Settings as SettingsIcon } from "lucide-react";
import { toast } from "sonner";
import { useAuthStore, isAdmin } from "@/stores/auth-store";
import { logout } from "@/lib/auth";
import { Avatar } from "@/components/ui/Avatar";
import { cn } from "@/lib/utils";

const navItems = [
  { href: "/leads", label: "Leads", icon: LayoutGrid, adminOnly: false },
  { href: "/analytics", label: "Analytics", icon: BarChart3, adminOnly: true },
  { href: "/settings", label: "Settings", icon: SettingsIcon, adminOnly: true },
];

export function NavBar() {
  const pathname = usePathname();
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const [menuOpen, setMenuOpen] = React.useState(false);

  async function handleLogout() {
    await logout();
    toast.success("Logged out.");
    router.push("/login");
  }

  return (
    <header className="sticky top-0 z-30 border-b border-border bg-white/95 shadow-[0_1px_2px_rgba(0,0,0,0.04)] backdrop-blur supports-[backdrop-filter]:bg-white/85">
      <div className="mx-auto flex h-14 max-w-[1128px] items-center justify-between px-4">
        <div className="flex items-center gap-2 sm:gap-6">
          <Link
            href="/leads"
            className="flex items-center gap-2 text-lg font-bold tracking-tight text-primary"
          >
            <span className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-sm font-bold text-white">
              SL
            </span>
            <span className="hidden sm:inline">SkillLeo</span>
          </Link>
          <nav className="flex items-center gap-1">
            {navItems
              .filter((item) => !item.adminOnly || isAdmin(user))
              .map((item) => {
                const active = pathname?.startsWith(item.href) ?? false;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={cn(
                      "relative flex flex-col items-center gap-0.5 px-3 py-2.5 text-[11px] font-medium text-text-secondary transition-colors hover:text-text-primary",
                      active && "text-text-primary",
                    )}
                  >
                    <item.icon className="h-5 w-5" strokeWidth={active ? 2.25 : 1.75} />
                    {item.label}
                    {active && (
                      <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-primary" />
                    )}
                  </Link>
                );
              })}
          </nav>
        </div>

        <div className="relative">
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex items-center gap-1.5 rounded-full p-1 hover:bg-black/5"
            aria-label="Account menu"
          >
            <Avatar name={user?.name ?? "?"} size="sm" />
            <ChevronDown className="h-4 w-4 text-text-tertiary" />
          </button>

          {menuOpen && (
            <>
              <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
              <div className="absolute right-0 z-20 mt-2 w-56 rounded-card border border-border bg-white py-2 shadow-popover">
                <div className="border-b border-border px-3 py-2">
                  <p className="truncate text-sm font-medium text-text-primary">{user?.name}</p>
                  <p className="truncate text-xs text-text-secondary">{user?.email}</p>
                  <span className="mt-1.5 inline-block rounded-pill bg-primary-tint px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
                    {user?.role}
                  </span>
                </div>
                <button
                  onClick={handleLogout}
                  className="flex w-full items-center gap-2 px-3 py-2 text-sm text-text-secondary hover:bg-black/5 hover:text-text-primary"
                >
                  <LogOut className="h-4 w-4" />
                  Log out
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
