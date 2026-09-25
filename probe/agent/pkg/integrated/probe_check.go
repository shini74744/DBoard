package integrated

import (
	"context"
	"github.com/nezhahq/agent/model"
	"github.com/nezhahq/agent/pkg/monitor"
	pb "github.com/nezhahq/agent/proto"
	"google.golang.org/grpc"
	"sync/atomic"
	"time"
)

var lastHealthy atomic.Int64

func Healthy() bool { return lastHealthy.Load() > time.Now().Unix()-60 }
func ValidateEndpoint(ctx context.Context, cfg model.AgentConfig) error {
	c := connectionConfigTuple{Server: cfg.Server, TLS: cfg.TLS, Auth: model.NewAuthHandler(cfg.ClientSecret, cfg.UUID, cfg.TLS)}
	conn, e := grpc.NewClient(c.Server, c.dialOptions()...)
	if e != nil {
		return e
	}
	defer conn.Close()
	_, e = pb.NewNezhaServiceClient(conn).ReportSystemInfo2(ctx, monitor.GetHost(&cfg).PB())
	return e
}
