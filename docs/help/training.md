---
description: Watch the narrated staff-training course inside FleetForge — your place is saved as you go, and super admins can see how far each team member has got.
---

# Training

The staff-training course is a set of narrated video chapters, one for each part of FleetForge. Open **Training** from the left sidebar.

## Watching the course

- The four tiles show **Chapters Completed**, **Course Progress** (weighted by chapter length, so a long chapter counts for more), **Time Watched** and **Time Remaining**.
- Pick any chapter from the **Chapters** list on the right, or click **Start Course** / **Continue Training** to open the first chapter you haven't finished.
- Captions are on by default. Use the **CC** button on the player to turn them off.
- **Previous** and **Next** step through the chapters in order.

## How your progress is saved

- Your position is saved every few seconds while a video plays, and whenever you pause, skip, switch chapters or close the tab.
- When you come back to a chapter it picks up where you stopped (shown as **Resumed at 4:12**). If you had only just started, or were on the last few seconds, it starts from the beginning.
- A chapter counts as **Completed** once you have watched 90% of it. The closing seconds are just the summary card. Rewinding to re-watch part of a chapter never lowers your progress.

## Team Report (super admins)

Super admins see a **Team Report** button on the Training page.

- Every active or invited team member is listed, **including people who have not started**, with chapters completed, overall progress and when they last watched.
- The tiles count **Completed Course** (every chapter completed), **In Progress** and **Not Started**.
- Search by name or email, or filter by status.
- Click a person to see their progress chapter by chapter: how much of each chapter they have watched, when they completed it and when they last watched it.

## Publishing new or re-recorded videos

Videos are stored with your other files (Amazon S3). Your administrator publishes them with `scripts/training/publish_videos.php`. Re-publishing a re-recorded chapter replaces the video but **keeps everyone's progress**.
