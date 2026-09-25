//go:build !agentcompat

package integrated

import "context"

func prepareFMSessionContext(ctx context.Context, _ string) (context.Context, func()) {
	return ctx, func() {}
}
