package rpc

import (
	"github.com/nezhahq/nezha/service/probebridge"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/metadata"
	"google.golang.org/grpc/status"
)

type probeStream struct{ grpc.ServerStream }

func (s *probeStream) authorize() error {
	md, _ := metadata.FromIncomingContext(s.Context())
	first := func(a, b string) string {
		v := md.Get(a)
		if len(v) == 0 {
			v = md.Get(b)
		}
		if len(v) > 0 {
			return v[0]
		}
		return ""
	}
	_, known, valid := probebridge.Authenticate(first("client-uuid", "client_uuid"), first("client-secret", "client_secret"))
	if known && !valid {
		return status.Error(codes.Unauthenticated, "probe credential revoked")
	}
	return nil
}
func (s *probeStream) RecvMsg(m any) error {
	if e := s.ServerStream.RecvMsg(m); e != nil {
		return e
	}
	return s.authorize()
}
func (s *probeStream) SendMsg(m any) error {
	if e := s.authorize(); e != nil {
		return e
	}
	return s.ServerStream.SendMsg(m)
}
func probeStreamAuth(srv any, stream grpc.ServerStream, info *grpc.StreamServerInfo, handler grpc.StreamHandler) error {
	return handler(srv, &probeStream{stream})
}
