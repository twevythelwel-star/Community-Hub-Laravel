import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { useToast } from '@/hooks/use-toast';
import { Sparkles, Wand2 } from 'lucide-react';
import { Separator } from '@/components/ui/separator';
import { AIConsentDialog } from '@/components/ai-consent-dialog';
import { useAuth } from '@/context/auth-context';
import { describeHttpError } from '@/lib/http';

/**
 * Composer for a community notice.
 *
 * Was: onSubmit called console.log and toasted "Notification Sent"; the AI
 * button called a client-side Genkit stub that returned four fixed strings
 * after a 1500ms sleep; and there was no way to choose who the notice reached
 * despite the server supporting exactly that.
 */

const formSchema = z.object({
  title: z.string().min(1, 'Title is required'),
  document: z.string().optional(),
  content: z.string().min(1, 'Notification content is required'),
  targetRoles: z.array(z.string()),
});

export type NotificationFormValues = z.infer<typeof formSchema>;

type NotificationFormProps = {
  /** Selectable audiences, from the server's UserRole enum. */
  roles: string[];
  /** Community name, for the AI prompt and the empty-audience label. */
  community: string;
  /** False when no GOOGLE_AI_API_KEY is configured; the helper returns a stub. */
  aiEnabled: boolean;
};

export function NotificationForm({ roles, community, aiEnabled }: NotificationFormProps) {
  const { user } = useAuth();
  const { toast } = useToast();

  const [isGenerating, setGenerating] = useState(false);
  const [isSubmitting, setSubmitting] = useState(false);
  const [showConsent, setShowConsent] = useState(false);

  const form = useForm<NotificationFormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      title: '',
      document: '',
      content: '',
      targetRoles: [],
    },
  });

  const selectedRoles = form.watch('targetRoles');

  /*
   * Consent now lives on the account rather than in localStorage, so the check
   * is a prop read. The dialog writes it and the server re-checks it before
   * anything leaves for Google.
   */
  async function handleGenerate() {
    if (!user?.aiConsent) {
      setShowConsent(true);
      return;
    }

    await executeGenerate();
  }

  async function executeGenerate() {
    const document = form.getValues('document');

    if (!document?.trim()) {
      toast({
        variant: 'destructive',
        title: 'Nothing to summarise',
        description: 'Paste the document you want turned into notice items first.',
      });
      return;
    }

    setGenerating(true);

    try {
      const { data } = await axios.post<{ notificationItems: string[]; source: string }>(
        '/dashboard/notifications/suggest-audience',
        { document },
      );

      form.setValue('content', data.notificationItems.map((item) => '- ' + item).join('\n'), {
        shouldValidate: true,
      });

      toast({
        title: 'Draft ready',
        description:
          data.source === 'gemini'
            ? 'Gemini drafted the notice items. Review them before publishing.'
            : 'No AI service is configured, so placeholder items were inserted. Edit them before publishing.',
      });
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Generation failed',
        description: describeHttpError(error),
      });
    } finally {
      setGenerating(false);
    }
  }

  function onSubmit(values: NotificationFormValues) {
    setSubmitting(true);

    router.post(
      '/dashboard/notifications',
      {
        title: values.title,
        content: values.content,
        target_roles: values.targetRoles,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          form.reset();
          toast({
            title: 'Notice published',
            description:
              values.targetRoles.length === 0
                ? 'It is now visible to all of ' + community + '.'
                : 'It is now visible to ' + values.targetRoles.join(', ') + '.',
          });
        },
        onError: (errors) => {
          toast({
            variant: 'destructive',
            title: 'Could not publish',
            description: Object.values(errors)[0] ?? 'Please check the form and try again.',
          });
        },
        onFinish: () => setSubmitting(false),
      },
    );
  }

  return (
    <>
      {showConsent && (
        <AIConsentDialog
          onAccept={() => {
            setShowConsent(false);
            executeGenerate();
          }}
          onDecline={() => setShowConsent(false)}
        />
      )}

      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
          <FormField
            control={form.control}
            name="title"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Notification Title</FormLabel>
                <FormControl>
                  <Input placeholder="e.g., Annual Maintenance Schedule" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <div className="grid md:grid-cols-2 gap-6 items-start">
            <div className="space-y-4">
              <h3 className="text-sm font-medium text-muted-foreground">
                AI Content Generation (Optional)
              </h3>

              <FormField
                control={form.control}
                name="document"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Document to Summarize</FormLabel>
                    <FormControl>
                      <Textarea
                        placeholder="Paste your long document or announcement text here..."
                        className="h-24"
                        {...field}
                      />
                    </FormControl>
                    <FormDescription>
                      Drafted for {community}. The text is sent to the server, which calls the AI
                      service — it never goes to Google from your browser.
                    </FormDescription>
                  </FormItem>
                )}
              />

              <Button
                type="button"
                onClick={handleGenerate}
                disabled={isGenerating}
                className="w-full"
              >
                <Wand2 className="mr-2 h-4 w-4" />
                {isGenerating ? 'Generating...' : 'Generate & Populate Content'}
              </Button>

              <p className="text-xs text-muted-foreground flex items-center gap-1">
                <Sparkles className="h-3 w-3" />
                {aiEnabled
                  ? 'Powered by Google Gemini'
                  : 'No AI service configured — this inserts placeholder text'}
              </p>
            </div>

            <FormField
              control={form.control}
              name="content"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Final Notification Content</FormLabel>
                  <FormControl>
                    <Textarea
                      placeholder="Write your notification content here, or generate it with AI."
                      className="min-h-[190px]"
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>

          <Separator />

          {/*
            The audience. `target_roles` has always existed on the notices table
            and Notification::forRole() has always filtered on it — nothing ever
            set it, so every "targeted" notice went to the whole estate.
          */}
          <FormField
            control={form.control}
            name="targetRoles"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Audience</FormLabel>
                <FormDescription>
                  {selectedRoles.length === 0
                    ? 'Nothing selected — this notice goes to everyone in ' + community + '.'
                    : 'Only ' + selectedRoles.join(', ') + ' will see this notice.'}
                </FormDescription>
                <div className="flex flex-wrap gap-4 pt-2">
                  {roles.map((role) => (
                    <label key={role} className="flex items-center gap-2 text-sm font-normal">
                      <Checkbox
                        checked={field.value.includes(role)}
                        onCheckedChange={(checked) =>
                          field.onChange(
                            checked
                              ? [...field.value, role]
                              : field.value.filter((r) => r !== role),
                          )
                        }
                      />
                      {role}
                    </label>
                  ))}
                </div>
                <FormMessage />
              </FormItem>
            )}
          />

          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Publishing...' : 'Send Notification'}
          </Button>
        </form>
      </Form>
    </>
  );
}
