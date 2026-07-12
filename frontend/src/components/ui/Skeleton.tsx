import { cn } from "@/lib/utils";

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn("skeleton rounded-md", className)} />;
}

export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <div className={cn("space-y-2", className)}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} className={cn("h-3", i === lines - 1 ? "w-2/3" : "w-full")} />
      ))}
    </div>
  );
}

export function LeadCardSkeleton() {
  return (
    <div className="rounded-card bg-surface p-5 shadow-card">
      <div className="flex items-start justify-between gap-4">
        <div className="flex-1 space-y-2">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-3 w-1/3" />
        </div>
        <Skeleton className="h-6 w-14 rounded-pill" />
      </div>
      <div className="mt-4 flex gap-2">
        <Skeleton className="h-5 w-20 rounded-pill" />
        <Skeleton className="h-5 w-24 rounded-pill" />
        <Skeleton className="h-5 w-16 rounded-pill" />
      </div>
    </div>
  );
}
