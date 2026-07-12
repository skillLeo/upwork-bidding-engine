import * as React from "react";
import { cn } from "@/lib/utils";

export function LeftRail({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <aside className={cn("hidden w-[220px] shrink-0 lg:block", className)}>
      <div className="sticky top-[72px] space-y-4">{children}</div>
    </aside>
  );
}

export function RightRail({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <aside className={cn("hidden w-[280px] shrink-0 xl:block", className)}>
      <div className="sticky top-[72px] space-y-4">{children}</div>
    </aside>
  );
}

export function PageContainer({ children, className }: { children: React.ReactNode; className?: string }) {
  return <div className={cn("mx-auto max-w-[1128px] px-4 py-6", className)}>{children}</div>;
}

export function ThreeColumn({ children }: { children: React.ReactNode }) {
  return <div className="flex items-start gap-4">{children}</div>;
}
