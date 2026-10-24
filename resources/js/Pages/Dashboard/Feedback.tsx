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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { ClientFormattedDate } from "@/components/client-formatted-date";
import { useToast } from "@/hooks/use-toast";
import { submit } from "@/lib/submit";
import {
  MessageSquare,
  AlertCircle,
  Lightbulb,
  CheckCircle2,
  Clock,
  ShieldCheck,
  Send,
} from "lucide-react";

type FeedbackType = "Issue" | "Suggestion";
type FeedbackStatus = "New" | "In Progress" | "Resolved";

type Submission = {
  id: number;
  type: FeedbackType;
  subject: string;
  body: string;
  status: FeedbackStatus;
  adminResponse: string | null;
  timestamp: string;
};

type Props = {
  submissions: Submission[];
};

export default function FeedbackPage({ submissions }: Props) {
  const { toast } = useToast();
  const [type, setType] = useState<FeedbackType>("Issue");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!subject.trim() || !body.trim()) {
      toast({
        variant: "destructive",
        title: "Validation Error",
        description: "Please complete both the subject and description fields.",
      });
      return;
    }

    setSubmitting(true);
    try {
      await submit("post", "/dashboard/feedback", {
        type,
        subject: subject.trim(),
        body: body.trim(),
      });
      toast({
        title: "Feedback Submitted",
        description: "Thank you. Your feedback has been received and routed to estate administration.",
      });
      setSubject("");
      setBody("");
      setType("Issue");
    } catch {
      toast({
        variant: "destructive",
        title: "Submission Refused",
        description: "The server could not process your submission. Please try again.",
      });
    } finally {
      setSubmitting(false);
    }
  };

  const getStatusBadge = (status: FeedbackStatus) => {
    switch (status) {
      case "New":
        return (
          <Badge variant="secondary" className="gap-1 bg-amber-500/10 text-amber-600 border-amber-500/20">
            <Clock className="w-3 h-3" />
            Under Review
          </Badge>
        );
      case "In Progress":
        return (
          <Badge variant="secondary" className="gap-1 bg-blue-500/10 text-blue-600 border-blue-500/20">
            <Clock className="w-3 h-3" />
            In Progress
          </Badge>
        );
      case "Resolved":
        return (
          <Badge variant="outline" className="gap-1 bg-emerald-500/10 text-emerald-600 border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3" />
            Resolved
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  return (
    <DashboardLayout>
      <Head title="Submit Feedback" />
      <div className="grid gap-8 max-w-5xl mx-auto pb-16">
        <div>
          <div className="flex items-center gap-2">
            <MessageSquare className="w-6 h-6 text-primary" />
            <h1 className="font-headline text-3xl font-bold tracking-tight">Resident Feedback &amp; Suggestions</h1>
          </div>
          <p className="text-muted-foreground mt-1">
            Report community issues, propose improvements, and view official responses from estate management.
          </p>
        </div>

        <div className="grid gap-8 lg:grid-cols-12 items-start">
          {/* Submission Form */}
          <Card className="lg:col-span-5 shadow-sm border-border/80">
            <form onSubmit={handleSubmit}>
              <CardHeader>
                <CardTitle className="text-lg">Submit Feedback</CardTitle>
                <CardDescription>
                  Your feedback helps maintain community standards and improve estate amenities.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-5">
                <div className="grid gap-2">
                  <Label>Category</Label>
                  <RadioGroup
                    value={type}
                    onValueChange={(v) => setType(v as FeedbackType)}
                    className="grid grid-cols-2 gap-3"
                  >
                    <div
                      className={`flex items-center space-x-2 border rounded-lg p-3 cursor-pointer transition-colors ${
                        type === "Issue"
                          ? "border-primary bg-primary/5 text-primary"
                          : "border-border hover:bg-muted/30"
                      }`}
                      onClick={() => setType("Issue")}
                    >
                      <RadioGroupItem value="Issue" id="feedback-type-issue" />
                      <Label htmlFor="feedback-type-issue" className="cursor-pointer font-medium flex items-center gap-1.5 text-xs">
                        <AlertCircle className="w-3.5 h-3.5 text-destructive" />
                        Report Issue
                      </Label>
                    </div>
                    <div
                      className={`flex items-center space-x-2 border rounded-lg p-3 cursor-pointer transition-colors ${
                        type === "Suggestion"
                          ? "border-primary bg-primary/5 text-primary"
                          : "border-border hover:bg-muted/30"
                      }`}
                      onClick={() => setType("Suggestion")}
                    >
                      <RadioGroupItem value="Suggestion" id="feedback-type-suggestion" />
                      <Label htmlFor="feedback-type-suggestion" className="cursor-pointer font-medium flex items-center gap-1.5 text-xs">
                        <Lightbulb className="w-3.5 h-3.5 text-amber-500" />
                        Suggestion
                      </Label>
                    </div>
                  </RadioGroup>
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="subject">Subject</Label>
                  <Input
                    id="subject"
                    maxLength={160}
                    placeholder={
                      type === "Issue"
                        ? "e.g., Damaged perimeter lighting near Gate 2"
                        : "e.g., Additional recycling bins in Central Park"
                    }
                    value={subject}
                    onChange={(e) => setSubject(e.target.value)}
                    required
                  />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="description">Detailed Description</Label>
                  <Textarea
                    id="description"
                    maxLength={5000}
                    rows={5}
                    placeholder="Provide specific details, locations, or recommendations..."
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    required
                  />
                  <span className="text-[11px] text-muted-foreground text-right">{body.length} / 5000 characters</span>
                </div>

                <Button type="submit" className="w-full gap-2" disabled={submitting}>
                  <Send className="w-4 h-4" />
                  {submitting ? "Submitting…" : "Send to Estate Management"}
                </Button>
              </CardContent>
            </form>
          </Card>

          {/* Previous Submissions & Status Tracking */}
          <div className="lg:col-span-7 space-y-4">
            <div>
              <h2 className="text-xl font-semibold tracking-tight">Your Feedback History</h2>
              <p className="text-sm text-muted-foreground">Track resolution status and read administrator responses.</p>
            </div>

            {submissions.length === 0 ? (
              <Card className="border-dashed bg-muted/20">
                <CardContent className="py-16 text-center">
                  <MessageSquare className="w-12 h-12 text-muted-foreground/30 mx-auto mb-3" />
                  <p className="text-base font-semibold text-foreground">No Feedback Submitted Yet</p>
                  <p className="text-xs text-muted-foreground mt-1 max-w-xs mx-auto">
                    Any issues or suggestions you submit will appear here along with estate administration replies.
                  </p>
                </CardContent>
              </Card>
            ) : (
              <div className="space-y-4">
                {submissions.map((item) => (
                  <Card key={item.id} className="transition-all duration-200 hover:shadow-sm border-border/80">
                    <CardHeader className="pb-3">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                          <div className="flex items-center gap-2">
                            <Badge variant={item.type === "Issue" ? "destructive" : "default"} className="text-xs">
                              {item.type}
                            </Badge>
                            {getStatusBadge(item.status)}
                          </div>
                          <CardTitle className="text-base font-semibold pt-1">{item.subject}</CardTitle>
                        </div>
                        <div className="text-xs text-muted-foreground">
                          <ClientFormattedDate date={item.timestamp} formatString="MMM d, yyyy" />
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <p className="text-sm text-muted-foreground leading-relaxed whitespace-pre-wrap">{item.body}</p>

                      {/* Official Admin Response */}
                      {item.adminResponse ? (
                        <div className="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-1.5">
                          <div className="flex items-center gap-1.5 text-xs font-semibold text-primary">
                            <ShieldCheck className="w-4 h-4" />
                            Official Estate Management Response
                          </div>
                          <p className="text-sm text-foreground/90 whitespace-pre-wrap leading-relaxed">
                            {item.adminResponse}
                          </p>
                        </div>
                      ) : (
                        <div className="text-xs text-muted-foreground/75 italic flex items-center gap-1">
                          <Clock className="w-3 h-3" />
                          Awaiting administrative review.
                        </div>
                      )}
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
