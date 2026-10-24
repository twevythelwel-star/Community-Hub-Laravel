

import { useState, useRef, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Camera, Check, FileQuestion, Video, VideoOff, AlertTriangle } from 'lucide-react';
import { Image } from '@/components/ui/image';
import { useToast } from '@/hooks/use-toast';
import { Alert, AlertDescription, AlertTitle } from '../ui/alert';
import type { VisitorStatus } from '@/types';

/**
 * Only the fields this modal actually reads.
 *
 * It previously required the full `Visitor` type from the mock data layer, whose
 * `id` was a string and whose dates were `Date` objects. Server rows use numeric
 * ids and ISO strings, so the prop is narrowed to what is used rather than
 * forcing callers to reshape a record just to open a dialog.
 */
export type VisitorIdSubject = {
  id: number | string;
  name: string;
  idImageUrl?: string | null;
  isBlocked: boolean;
  status: VisitorStatus;
};

type VisitorIdModalProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  visitor: VisitorIdSubject;
  onStatusChange: (visitorId: VisitorIdSubject['id'], newStatus: VisitorStatus) => void;
  onReportMismatch: (visitor: VisitorIdSubject) => void;
};

export function VisitorIdModal({ open, onOpenChange, visitor, onStatusChange, onReportMismatch }: VisitorIdModalProps) {
  const { toast } = useToast();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [isCapturing, setIsCapturing] = useState(false);
  const [hasCameraPermission, setHasCameraPermission] = useState<boolean | null>(null);

  useEffect(() => {
    // Capture the current node so the cleanup below acts on the same element
    // the effect ran against, not whatever videoRef points to at cleanup time.
    const videoElement = videoRef.current;

    const getCameraPermission = async () => {
      if (open && isCapturing) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast({
              variant: 'destructive',
              title: 'Camera Not Supported',
              description: 'Your browser does not support camera access.',
            });
            setHasCameraPermission(false);
            return;
        }

        try {
          const stream = await navigator.mediaDevices.getUserMedia({ video: true });
          setHasCameraPermission(true);
          if (videoElement) {
            videoElement.srcObject = stream;
          }
        } catch (error) {
          console.error('Error accessing camera:', error);
          setHasCameraPermission(false);
          toast({
            variant: 'destructive',
            title: 'Camera Access Denied',
            description: 'Please enable camera permissions in your browser settings to use this feature.',
          });
        }
      } else {
        // Stop camera stream when modal is closed or capture is cancelled
        if (videoElement && videoElement.srcObject) {
            const stream = videoElement.srcObject as MediaStream;
            stream.getTracks().forEach(track => track.stop());
            videoElement.srcObject = null;
        }
      }
    };

    getCameraPermission();

    // Cleanup function to stop tracks when component unmounts or dependencies change
    return () => {
        if (videoElement && videoElement.srcObject) {
            const stream = videoElement.srcObject as MediaStream;
            stream.getTracks().forEach(track => track.stop());
        }
    }

  }, [open, isCapturing, toast]);
  
  const handleCaptureClick = () => {
    setIsCapturing(prev => !prev);
    if (isCapturing) {
        setHasCameraPermission(null); // Reset permission state
    }
  };
  
  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
        setIsCapturing(false);
        setHasCameraPermission(null);
    }
    onOpenChange(isOpen);
  }

  const handleAllowEntry = () => {
    onStatusChange(visitor.id, 'Checked In');
    onOpenChange(false); // Close the modal
  };

  const handleReportMismatch = () => {
    onReportMismatch(visitor);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Verify Visitor ID: {visitor.name}</DialogTitle>
          <DialogDescription>
             {isCapturing ? 'Position the ID card within the frame.' : "Confirm the visitor's identity using their provided ID."}
          </DialogDescription>
        </DialogHeader>

        <div className="flex justify-center items-center p-4 my-4 rounded-lg bg-muted min-h-[200px]">
          {isCapturing ? (
            <div className="w-full space-y-2">
                <video ref={videoRef} className="w-full aspect-video rounded-md" autoPlay muted playsInline />
                {hasCameraPermission === false && (
                    <Alert variant="destructive">
                        <VideoOff className="h-4 w-4" />
                        <AlertTitle>Camera Access Required</AlertTitle>
                        <AlertDescription>
                            Please allow camera access in your browser to use this feature.
                        </AlertDescription>
                    </Alert>
                )}
            </div>
          ) : visitor.idImageUrl ? (
            <Image
              src={visitor.idImageUrl}
              alt={`ID for ${visitor.name}`}
              width={300}
              height={200}
              className="object-contain rounded-md"
              data-ai-hint="identification card"
            />
          ) : (
            <div className="text-center text-muted-foreground">
              <FileQuestion className="mx-auto h-12 w-12" />
              <p className="mt-2">No ID was uploaded by the homeowner.</p>
            </div>
          )}
        </div>

        <DialogFooter className="sm:justify-center flex-col sm:flex-col sm:space-x-0 gap-2">
            <Button 
                type="button" 
                onClick={handleAllowEntry} 
                disabled={visitor.isBlocked || visitor.status === 'Checked In'}
            >
                <Check className="mr-2 h-4 w-4" />
                Allow Entry
            </Button>
           {isCapturing && hasCameraPermission && (
             <Button type="button">
                <Camera className="mr-2 h-4 w-4" />
                Take Snapshot
            </Button>
           )}
            <Button type="button" variant="outline" onClick={handleCaptureClick}>
              {isCapturing ? <VideoOff className="mr-2 h-4 w-4" /> : <Video className="mr-2 h-4 w-4" />}
              {isCapturing ? 'Cancel Capture' : 'Capture New ID'}
            </Button>
             <Button type="button" variant="destructive" onClick={handleReportMismatch}>
              <AlertTriangle className="mr-2 h-4 w-4" />
              Report ID Mismatch
            </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

    
