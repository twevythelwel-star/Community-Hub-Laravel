import { useState, type FormEvent } from "react";
import { Head } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { ClientFormattedDate } from "@/components/client-formatted-date";
import { useAuth } from "@/context/auth-context";
import { GitCommit, PlusCircle, Sparkles, Calendar, Layers } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { submit } from "@/lib/submit";

type ChangelogEntry = {
  id: number;
  version: string;
  releasedOn: string;
  title: string;
  body: string;
};

type Props = {
  entries: ChangelogEntry[];
  canManage?: boolean;
};

export default function ChangelogPage({ entries, canManage }: Props) {
  const { user } = useAuth();
  const { toast } = useToast();
  const [isOpen, setIsOpen] = useState(false);
  const [version, setVersion] = useState("");
  const [title, setTitle] = useState("");
  const [releasedOn, setReleasedOn] = useState(new Date().toISOString().split("T")[0]);
  const [body, setBody] = useState("");
  const [submitting, setSubmitting] = useState(false);

  if (user?.role !== "System Admin") {
    return (
      <DashboardLayout>
        <Head title="App Changelog" />
        <Card className="max-w-md mx-auto mt-12 border-destructive/20 shadow-sm">
          <CardHeader>
            <CardTitle className="text-destructive font-headline">Access Restricted</CardTitle>
            <CardDescription>
              The application system changelog is strictly reserved for System Administrators.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">
              You do not have the required administrative permissions to review system release history.
            </p>
          </CardContent>
        </Card>
      </DashboardLayout>
    );
  }

  const handleCreateEntry = async (e: FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await submit("post", "/dashboard/changelog", {
        version,
        title,
        released_on: releasedOn,
        body,
      });
      toast({
        title: "Release Notes Published",
        description: `Version ${version} has been added to the application changelog.`,
      });
      setIsOpen(false);
      setVersion("");
      setTitle("");
      setBody("");
    } catch {
      toast({
        variant: "destructive",
        title: "Publish Failed",
        description: "Could not save release notes. Please check the inputs and try again.",
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <DashboardLayout>
      <Head title="App Changelog" />
      <div className="grid gap-8 max-w-5xl mx-auto pb-16">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <Layers className="w-6 h-6 text-primary" />
              <h1 className="font-headline text-3xl font-bold tracking-tight">System Changelog</h1>
            </div>
            <p className="text-muted-foreground mt-1">
              Production deployment records, feature rollouts, and infrastructure enhancements.
            </p>
          </div>

          {canManage && (
            <Dialog open={isOpen} onOpenChange={setIsOpen}>
              <DialogTrigger asChild>
                <Button className="gap-2 shadow-sm">
                  <PlusCircle className="w-4 h-4" />
                  Publish Release
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-lg">
                <form onSubmit={handleCreateEntry}>
                  <DialogHeader>
                    <DialogTitle className="font-headline">Publish New Release Notes</DialogTitle>
                    <DialogDescription>
                      Record an official platform update in the system audit history.
                    </DialogDescription>
                  </DialogHeader>

                  <div className="grid gap-4 py-4">
                    <div className="grid grid-cols-2 gap-4">
                      <div className="grid gap-2">
                        <Label htmlFor="version">Version Tag</Label>
                        <Input
                          id="version"
                          placeholder="e.g., v2.5.0"
                          value={version}
                          onChange={(e) => setVersion(e.target.value)}
                          required
                        />
                      </div>
                      <div className="grid gap-2">
                        <Label htmlFor="released_on">Release Date</Label>
                        <Input
                          id="released_on"
                          type="date"
                          value={releasedOn}
                          onChange={(e) => setReleasedOn(e.target.value)}
                          required
                        />
                      </div>
                    </div>

                    <div className="grid gap-2">
                      <Label htmlFor="release_title">Release Title</Label>
                      <Input
                        id="release_title"
                        placeholder="e.g., Digital Gate Pass Engine & Boundary Mapping"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        required
                      />
                    </div>

                    <div className="grid gap-2">
                      <Label htmlFor="release_body">Release Summary &amp; Changes</Label>
                      <Textarea
                        id="release_body"
                        placeholder="Detail key architectural enhancements, security upgrades, or bug fixes..."
                        rows={6}
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        required
                      />
                    </div>
                  </div>

                  <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setIsOpen(false)}>
                      Cancel
                    </Button>
                    <Button type="submit" disabled={submitting}>
                      {submitting ? "Publishing…" : "Publish Release"}
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>
          )}
        </div>

        {entries.length === 0 ? (
          <Card className="border-dashed bg-muted/20">
            <CardContent className="py-16 text-center">
              <GitCommit className="w-12 h-12 text-muted-foreground/40 mx-auto mb-4" />
              <p className="font-semibold text-foreground text-lg">No Changelog Entries Recorded</p>
              <p className="text-sm text-muted-foreground mt-1 max-w-sm mx-auto">
                Release updates and system version notes will appear here as production builds are registered.
              </p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-6 relative before:absolute before:inset-0 before:left-4 md:before:left-6 before:w-0.5 before:bg-border/60 before:z-0">
            {entries.map((entry) => (
              <div key={entry.id} className="relative z-10 pl-10 md:pl-14">
                <div className="absolute left-2.5 md:left-4.5 -translate-x-1/2 top-4 w-4 h-4 rounded-full border-2 border-background bg-primary shadow-sm flex items-center justify-center" />

                <Card className="border-border/80 shadow-sm transition-all duration-200 hover:shadow-md">
                  <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-3">
                        <Badge variant="default" className="font-mono text-xs px-2.5 py-0.5 font-bold tracking-wide">
                          {entry.version}
                        </Badge>
                        <CardTitle className="text-lg font-semibold">{entry.title}</CardTitle>
                      </div>
                      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Calendar className="w-3.5 h-3.5" />
                        <ClientFormattedDate date={entry.releasedOn} formatString="MMMM d, yyyy" />
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="text-sm text-muted-foreground leading-relaxed whitespace-pre-wrap rounded-lg bg-muted/30 p-4 border border-border/40 font-normal">
                      {entry.body}
                    </div>
                  </CardContent>
                </Card>
              </div>
            ))}
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
